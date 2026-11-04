<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerIdentity;
use App\Modules\Sales\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Finds the customer card for a buyer coming from a channel, or makes one. The match is, in order of certainty:
 *
 *   1. the buyer's id on that store (customer_identities),
 *   2. the full phone number,
 *   3. the email.
 *
 * Never by name: two buyers with the same name are two customers until someone merges them by hand. A channel never
 * blanks what the card already has: only empty fields of the card are filled from the order. The store id is
 * remembered on the card, so the next order of that buyer is found even if they change phone.
 */
class CustomerResolver
{
    public function __construct(private readonly CustomerService $customers) {}

    /**
     * @param  array{name?: string|null, phone?: string|null, email?: string|null, address?: string|null}  $buyer
     * @param  array{source: string, account_id?: int|null, external_id?: string|null, label?: string|null}|null  $identity
     * @param  string|null  $channelType  the source to record on a new card (Customer::SOURCES)
     */
    public function resolve(array $buyer, ?array $identity = null, ?string $channelType = null): ?Customer
    {
        $customer = $this->byIdentity($identity) ?? $this->byPhone($buyer['phone'] ?? null) ?? $this->byEmail($buyer['email'] ?? null);

        if ($customer === null) {
            if (blank($buyer['name'] ?? null) || (blank($buyer['phone'] ?? null) && blank($buyer['email'] ?? null) && blank($identity['external_id'] ?? null))) {
                return null; // nothing to know the buyer by again: the order keeps its buyer details, no card is made
            }
            $phone = PhoneNumber::normalize($buyer['phone'] ?? null);
            $customer = $this->customers->findOrCreate(array_filter([
                'name' => $buyer['name'],
                'phone' => $phone !== null && PhoneNumber::isValid($phone) ? $phone : null,
                'email' => filter_var($buyer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
                'address' => $buyer['address'] ?? null,
                'source' => in_array($channelType, Customer::SOURCES, true) ? $channelType : null,
            ], fn ($v) => filled($v)));
        } else {
            $this->fillBlanks($customer, $buyer);
        }

        $this->remember($customer, $identity);

        return $customer;
    }

    /** @param  array{source: string, account_id?: int|null, external_id?: string|null}|null  $identity */
    private function byIdentity(?array $identity): ?Customer
    {
        if (blank($identity['external_id'] ?? null)) {
            return null;
        }

        return CustomerIdentity::query()->where('source', $identity['source'])->where('marketplace_account_id', $identity['account_id'] ?? null)
            ->where('external_id', (string) $identity['external_id'])->first()?->customer;
    }

    private function byPhone(?string $phone): ?Customer
    {
        $phone = PhoneNumber::normalize($phone);

        return $phone !== null && PhoneNumber::isValid($phone) ? $this->customers->findByPhone($phone) : null;
    }

    private function byEmail(?string $email): ?Customer
    {
        $key = Customer::emailKey($email);

        // Two cards with the same email cannot tell which is the buyer: then none is picked.
        $matches = $key !== null && filter_var($key, FILTER_VALIDATE_EMAIL) ? Customer::query()->where('email_key', $key)->limit(2)->get() : collect();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param  array{name?: string|null, phone?: string|null, email?: string|null, address?: string|null}  $buyer */
    private function fillBlanks(Customer $customer, array $buyer): void
    {
        $changed = false;
        if (blank($customer->email) && ($email = filter_var($buyer['email'] ?? null, FILTER_VALIDATE_EMAIL))) {
            $customer->email = $email;
            $changed = true;
        }
        if (blank($customer->address) && filled($buyer['address'] ?? null)) {
            $customer->address = mb_substr((string) $buyer['address'], 0, 500);
            $changed = true;
        }
        $phone = PhoneNumber::normalize($buyer['phone'] ?? null);
        if (blank($customer->phone) && $phone !== null && PhoneNumber::isValid($phone) && $this->customers->findByPhone($phone) === null) {
            $customer->setPhone($phone);
            $changed = true;
        }
        if ($changed) {
            $customer->save();
        }
    }

    /** @param  array{source: string, account_id?: int|null, external_id?: string|null, label?: string|null}|null  $identity */
    private function remember(Customer $customer, ?array $identity): void
    {
        if (blank($identity['external_id'] ?? null)) {
            return;
        }
        $keys = ['source' => $identity['source'], 'marketplace_account_id' => $identity['account_id'] ?? null, 'external_id' => (string) $identity['external_id']];
        try {
            CustomerIdentity::query()->updateOrCreate($keys, [
                'customer_id' => CustomerIdentity::query()->where($keys)->value('customer_id') ?? $customer->id,
                'label' => $identity['label'] ?? null,
                'last_seen_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Recorded by another order of the same buyer a moment ago.
        }
    }
}
