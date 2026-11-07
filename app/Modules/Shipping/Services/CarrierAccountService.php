<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Connectors\Data\AccountInfo;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Validation\ValidationException;

/** Connect, check and disconnect the shop's carrier accounts ("Kết nối 1 bước"). */
class CarrierAccountService
{
    public function __construct(private readonly CarrierRegistry $carriers, private readonly AuditLogger $audit) {}

    /** Checks the credentials with the carrier, then saves them with each warehouse matched to a pickup point. */
    public function connect(string $carrier, array $credentials, string $environment): CarrierAccount
    {
        $info = $this->verifyWith($carrier, $credentials, $environment);
        $account = CarrierAccount::query()
            ->where('carrier', $carrier)->where('environment', $environment)
            ->when($info->externalAccount !== null, fn ($q) => $q->where('external_account', $info->externalAccount))
            ->first() ?? new CarrierAccount(['carrier' => $carrier, 'environment' => $environment]);

        $account->fill([
            'name' => mb_substr($info->name, 0, 150),
            'external_account' => $info->externalAccount,
            'status' => CarrierAccount::CONNECTED,
            'credentials' => $info->credentials ?? $credentials,
            'locations' => $info->locations,
            'pickups' => $this->matchPickups($info->locations, (array) ($account->pickups ?? [])),
            'last_verified_at' => now(),
            'last_error' => null,
        ])->save();
        $this->audit->record('carrier.connected', $account, ['carrier' => $carrier, 'environment' => $environment, 'account' => $info->externalAccount]);

        return $account;
    }

    /** "Kiểm tra kết nối": asks the carrier again; a refused account stops being offered until it works. */
    public function check(CarrierAccount $account): CarrierAccount
    {
        try {
            $info = $this->carriers->get($account->carrier)->verify((array) $account->credentials, $account->environment);
            $account->fill(['status' => CarrierAccount::CONNECTED, 'locations' => $info->locations, 'last_verified_at' => now(), 'last_error' => null]);
            if ($info->credentials !== null) {
                $account->credentials = $info->credentials;
            }
            $account->save();
        } catch (CarrierException $e) {
            $account->fill(['status' => $e->authFailed ? CarrierAccount::ERROR : $account->status, 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
        }

        return $account;
    }

    /** @param  array<string, string|null>  $pickups  warehouse ulid => pickup point id (null = first point) */
    public function setPickups(CarrierAccount $account, array $pickups): CarrierAccount
    {
        $byId = collect($account->locations ?? [])->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('ulid', array_keys($pickups))->pluck('id', 'ulid');
        $map = (array) ($account->pickups ?? []);
        foreach ($pickups as $ulid => $pointId) {
            $id = $warehouses[$ulid] ?? throw ValidationException::withMessages(['pickups' => 'Kho không tồn tại.']);
            if ($pointId === null) {
                unset($map[(string) $id]);

                continue;
            }
            $map[(string) $id] = $byId[$pointId] ?? throw ValidationException::withMessages(['pickups' => 'Điểm lấy hàng không thuộc tài khoản này.']);
        }
        $account->pickups = $map;
        $account->save();
        $this->audit->record('carrier.pickups_changed', $account, ['carrier' => $account->carrier]);

        return $account;
    }

    /** Forgets the credentials. Shipments already made keep their history; the account can be connected again. */
    public function disconnect(CarrierAccount $account): void
    {
        $account->fill(['status' => CarrierAccount::DISCONNECTED, 'credentials' => null])->save();
        $this->audit->record('carrier.disconnected', $account, ['carrier' => $account->carrier]);
    }

    private function verifyWith(string $carrier, array $credentials, string $environment): AccountInfo
    {
        if (! $this->carriers->has($carrier)) {
            throw ValidationException::withMessages(['carrier' => 'Hãng này chưa kết nối được.']);
        }
        try {
            return $this->carriers->get($carrier)->verify($credentials, $environment);
        } catch (CarrierException $e) {
            throw ValidationException::withMessages(['credentials' => $e->getMessage()]);
        }
    }

    /**
     * Each warehouse gets the pickup point in its province (else the first point), unless the shop already chose one.
     *
     * @param  array<int, array<string, mixed>>  $locations
     * @param  array<int|string, array<string, mixed>>  $current
     */
    private function matchPickups(array $locations, array $current): array
    {
        if ($locations === []) {
            return [];
        }
        $ids = array_column($locations, 'id');
        $map = [];
        foreach (Warehouse::query()->get(['id', 'province_code']) as $warehouse) {
            $kept = $current[$warehouse->id] ?? null;
            if ($kept !== null && in_array($kept['id'] ?? null, $ids, true)) {
                $map[(string) $warehouse->id] = $kept;

                continue;
            }
            $map[(string) $warehouse->id] = collect($locations)->first(fn ($l) => filled($warehouse->province_code) && ($l['province_code'] ?? null) === $warehouse->province_code) ?? $locations[0];
        }

        return $map;
    }
}
