<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Support\PhoneNumber;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public const BULK_ACTIONS = ['set_group', 'set_status', 'add_tag', 'remove_tag', 'assign', 'delete'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  name, phone, email, address, note, code, group_id, assigned_user_id, birthday, gender, tax_code, tags, status
     */
    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = new Customer;
            $this->fill($customer, $data);
            $customer->forceFill(['code' => $this->code($data['code'] ?? null, null), 'created_by' => Auth::id()])->save();
            $this->audit->record('customer.created', $customer, ['code' => $customer->code]);

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $this->fill($customer, $data);
        if (filled($data['code'] ?? null) && $data['code'] !== $customer->code) {
            $customer->code = $this->code($data['code'], $customer);
        }
        $changes = $this->changes($customer);
        $customer->save();
        if ($changes) {
            $this->audit->record('customer.updated', $customer, ['code' => $customer->code, 'fields' => $changes]);
        }

        return $customer;
    }

    /** Personal data is logged as changed, never with its values. */
    public const PRIVATE_FIELDS = ['phone', 'phone_hash', 'phone_last4', 'address', 'email', 'birthday', 'tax_code'];

    /**
     * Fields about to change, each with its old and new value (personal data only flagged as changed).
     *
     * @return array<string, array{from: mixed, to: mixed}|string>
     */
    private function changes(Customer $customer): array
    {
        $changes = [];
        foreach (array_keys($customer->getDirty()) as $field) {
            if (in_array($field, ['updated_at', 'phone_hash', 'phone_last4'], true)) {
                continue;
            }
            $changes[$field] = in_array($field, self::PRIVATE_FIELDS, true)
                ? 'changed'
                : ['from' => $customer->getOriginal($field), 'to' => $customer->getAttribute($field)];
        }

        return $changes;
    }

    public function delete(Customer $customer): void
    {
        if ($customer->orders()->exists()) {
            throw ValidationException::withMessages(['customer' => 'Khách đã có đơn hàng, không thể xóa. Hãy chuyển sang ngừng giao dịch.']);
        }
        $customer->delete();
        $this->audit->record('customer.deleted', $customer, ['code' => $customer->code]);
    }

    /**
     * Applies one action to many customers. Deleting skips customers that already have orders.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array{updated: int, skipped: list<array{id: string, message: string}>}
     */
    public function bulk(Collection $customers, string $action, ?string $value): array
    {
        $group = $action === 'set_group' ? $this->group($value) : null;
        $assignee = $action === 'assign' ? $this->member($value) : null;
        $tag = null;
        if (in_array($action, ['add_tag', 'remove_tag'], true)) {
            $tag = $this->tags([$value])[0] ?? throw ValidationException::withMessages(['value' => 'Nhập tag.']);
        }
        if ($action === 'set_status' && ! in_array($value, Customer::STATUSES, true)) {
            throw ValidationException::withMessages(['value' => 'Trạng thái không hợp lệ.']);
        }

        return DB::transaction(function () use ($customers, $action, $value, $group, $assignee, $tag) {
            $updated = 0;
            $skipped = [];
            foreach ($customers as $customer) {
                if ($action === 'delete') {
                    if ($customer->orders()->exists()) {
                        $skipped[] = ['id' => $customer->ulid, 'message' => "{$customer->code} đã có đơn hàng"];

                        continue;
                    }
                    $customer->delete();
                    $this->audit->record('customer.deleted', $customer, ['code' => $customer->code]);
                    $updated++;

                    continue;
                }

                match ($action) {
                    'set_group' => $customer->customer_group_id = $group?->id,
                    'set_status' => $customer->status = (string) $value,
                    'assign' => $customer->assigned_user_id = $assignee?->id,
                    'add_tag' => $customer->tags = $this->tags([...($customer->tags ?? []), $tag]),
                    'remove_tag' => $customer->tags = array_values(array_filter($customer->tags ?? [], fn ($t) => mb_strtolower($t) !== mb_strtolower((string) $tag))) ?: null,
                    default => throw ValidationException::withMessages(['action' => 'Thao tác không hợp lệ.']),
                };
                if ($customer->isDirty()) {
                    $this->audit->record('customer.updated', $customer, ['code' => $customer->code, 'fields' => $this->changes($customer), 'bulk' => $action]);
                    $customer->save();
                    $updated++;
                }
            }
            if ($action !== 'delete' && $updated) {
                $this->audit->record('customer.bulk_updated', null, ['action' => $action, 'value' => $value, 'count' => $updated]);
            }

            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * Finds the customer by phone, or creates one. Used when an order is entered with a new buyer.
     *
     * @param  array{name: string, phone?: string|null, address?: string|null, email?: string|null}  $data
     */
    public function findOrCreate(array $data): Customer
    {
        $phone = PhoneNumber::normalize($data['phone'] ?? null);
        if ($phone !== null && ($existing = $this->findByPhone($phone))) {
            return $existing;
        }

        try {
            return $this->create($data);
        } catch (UniqueConstraintViolationException $e) {
            // Another request created the same phone a moment ago: use that customer. The locking read sees rows
            // committed after this transaction started.
            $hash = PhoneNumber::hash($phone);

            return ($hash ? Customer::query()->where('phone_hash', $hash)->lockForUpdate()->first() : null) ?? throw $e;
        }
    }

    public function findByPhone(?string $phone): ?Customer
    {
        $hash = PhoneNumber::hash(PhoneNumber::normalize($phone));

        return $hash ? Customer::query()->where('phone_hash', $hash)->first() : null;
    }

    /**
     * Trims, drops empties and case-insensitive duplicates.
     *
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    public function tags(array $tags): array
    {
        return collect($tags)->map(fn ($t) => mb_substr(trim((string) $t), 0, 40))->filter()
            ->unique(fn ($t) => mb_strtolower($t))->take(20)->values()->all();
    }

    public function group(?string $ulid): ?CustomerGroup
    {
        if (blank($ulid)) {
            return null;
        }

        return CustomerGroup::query()->where('ulid', $ulid)->first()
            ?? throw ValidationException::withMessages(['group_id' => 'Nhóm khách hàng không tồn tại.']);
    }

    public function member(?string $ulid): ?User
    {
        if (blank($ulid)) {
            return null;
        }

        $user = User::query()->where('ulid', $ulid)->first();
        $isMember = $user && TenantUser::query()->where('user_id', $user->id)->where('status', TenantUser::STATUS_ACTIVE)->exists();
        if (! $isMember) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Nhân viên phụ trách không thuộc cửa hàng này.']);
        }

        return $user;
    }

    /**
     * The code typed in (unique in the shop), or the next automatic KH-xxxxxx not already taken by a typed code.
     */
    private function code(?string $code, ?Customer $customer): string
    {
        $code = trim((string) $code);
        if ($code !== '') {
            $taken = Customer::query()->where('code', $code)->when($customer, fn ($q) => $q->whereKeyNot($customer->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => "Mã khách {$code} đã được dùng."]);
            }

            return $code;
        }

        do {
            $code = DocumentNumber::next('KH');
        } while (Customer::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Customer $customer, array $data): void
    {
        $customer->fill(array_intersect_key($data, array_flip(['name', 'email', 'address', 'note', 'birthday', 'gender', 'tax_code', 'status', 'payment_terms_days', 'credit_limit'])));

        if (array_key_exists('tags', $data)) {
            $customer->tags = $this->tags((array) $data['tags']) ?: null;
        }
        if (array_key_exists('group_id', $data)) {
            $customer->customer_group_id = $this->group($data['group_id'])?->id;
        }
        if (array_key_exists('assigned_user_id', $data)) {
            $customer->assigned_user_id = $this->member($data['assigned_user_id'])?->id;
        }

        if (array_key_exists('phone', $data)) {
            $phone = PhoneNumber::normalize($data['phone']);
            if ($phone !== null && ! PhoneNumber::isValid($phone)) {
                throw ValidationException::withMessages(['phone' => 'Số điện thoại không hợp lệ.']);
            }
            $other = $this->findByPhone($phone);
            if ($other && $other->id !== $customer->id) {
                throw ValidationException::withMessages(['phone' => "Số điện thoại đã thuộc khách {$other->code} · {$other->name}."]);
            }
            $customer->setPhone($phone);
        }
    }
}
