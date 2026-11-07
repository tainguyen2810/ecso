<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Connectors\CarrierException;
use App\Modules\Shipping\Connectors\CarrierRegistry;
use App\Modules\Shipping\Connectors\Data\ServiceQuote;
use App\Modules\Shipping\Connectors\Data\ShipmentDraft;
use App\Modules\Shipping\Models\CarrierAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/** "So sánh giá": every connected account prices the parcel; one account failing never hides the others. */
class QuoteService
{
    public function __construct(private readonly CarrierRegistry $carriers) {}

    /**
     * @return array{accounts: array<int, array<string, mixed>>, cheapest: array<string, mixed>|null, fastest: array<string, mixed>|null}
     */
    public function quote(ShipmentDraft $draft): array
    {
        $accounts = CarrierAccount::query()->where('status', CarrierAccount::CONNECTED)->orderBy('id')->get()
            ->filter(fn (CarrierAccount $a) => $this->carriers->has($a->carrier));
        $rows = [];
        $all = [];
        foreach ($accounts as $account) {
            $connector = $this->carriers->get($account->carrier);
            $row = ['account' => ['id' => $account->ulid, 'carrier' => $account->carrier, 'carrier_label' => $connector->label(), 'name' => $account->name], 'services' => [], 'error' => null];
            $pickup = $account->pickupFor($draft->warehouseId);
            try {
                if ($pickup === null) {
                    throw new CarrierException('Chưa chọn điểm lấy hàng cho kho này.');
                }
                $quotes = $connector->quote($account, $pickup, $draft);
                usort($quotes, fn (ServiceQuote $a, ServiceQuote $b) => bccomp($a->fee, $b->fee, 2));
                $row['services'] = array_map(fn (ServiceQuote $q) => $q->toArray(), $quotes);
                foreach ($quotes as $q) {
                    $all[] = ['account_id' => $account->ulid, 'carrier_label' => $connector->label(), ...$q->toArray()];
                }
            } catch (CarrierException $e) {
                $row['error'] = $e->getMessage();
            } catch (Throwable $e) {
                Log::warning('carrier.quote_failed', ['carrier' => $account->carrier, 'error' => $e->getMessage()]);
                $row['error'] = 'Hãng không trả lời. Thử lại sau.';
            }
            $rows[] = $row;
        }

        $cheapest = collect($all)->sortBy(fn ($q) => (float) $q['fee'])->first();
        $fastest = collect($all)->filter(fn ($q) => $q['expected_at'] !== null)->sortBy([fn ($a, $b) => strcmp($a['expected_at'], $b['expected_at']), fn ($a, $b) => (float) $a['fee'] <=> (float) $b['fee']])->first();

        return ['accounts' => $rows, 'cheapest' => $cheapest, 'fastest' => $fastest];
    }
}
