<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Support\DocumentNumber;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, address?: string|null, group?: string|null, note?: string|null}  $data
     */
    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = new Customer;
            $this->fill($customer, $data);
            $customer->forceFill(['code' => DocumentNumber::next('KH'), 'created_by' => Auth::id()])->save();
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
        $customer->save();
        $this->audit->record('customer.updated', $customer, ['code' => $customer->code]);

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        if ($customer->orders()->exists()) {
            throw ValidationException::withMessages(['customer' => 'Khách đã có đơn hàng, không thể xóa.']);
        }
        $customer->delete();
        $this->audit->record('customer.deleted', $customer, ['code' => $customer->code]);
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

        return $this->create($data);
    }

    public function findByPhone(?string $phone): ?Customer
    {
        $hash = PhoneNumber::hash(PhoneNumber::normalize($phone));

        return $hash ? Customer::query()->where('phone_hash', $hash)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Customer $customer, array $data): void
    {
        $customer->fill(array_intersect_key($data, array_flip(['name', 'email', 'address', 'group', 'note'])));

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
