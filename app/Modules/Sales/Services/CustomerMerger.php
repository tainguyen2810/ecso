<?php

namespace App\Modules\Sales\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Geo\Services\VnAddress;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\CustomerMerge;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merges a duplicate customer card into the card that is kept. Everything of the duplicate moves over: orders,
 * payments, debt documents, cash vouchers, delivery addresses, notes, channel ids. Its own phone and address are
 * kept as an extra delivery address when the kept card has others, its tags are added, and for each field the person
 * merging chooses which card's value stays. The duplicate card is then removed, kept in full (encrypted) in the merge
 * record. Both cards are always of the same business (the route only finds cards of the current one).
 */
class CustomerMerger
{
    /** Fields the person merging can take from the duplicate card. */
    public const FIELDS = ['name', 'phone', 'email', 'address', 'birthday', 'gender', 'tax_code', 'type', 'source', 'invoice_name', 'invoice_address', 'invoice_email', 'customer_group_id', 'assigned_user_id', 'payment_terms_days', 'credit_limit', 'note'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * What a merge would do: how many documents move, and the fields where the two cards differ.
     *
     * @return array{moves: array<string, int>, differences: list<array{field: string, keep: mixed, merge: mixed}>}
     */
    public function preview(Customer $keep, Customer $merge): array
    {
        $this->guard($keep, $merge);
        $differences = [];
        foreach (self::FIELDS as $field) {
            $a = $this->value($keep, $field);
            $b = $this->value($merge, $field);
            if (filled($b) && (string) $a !== (string) $b) {
                $differences[] = ['field' => $field, 'keep' => $a, 'merge' => $b];
            }
        }

        return ['moves' => $this->counts($merge), 'differences' => $differences];
    }

    /**
     * @param  list<string>  $takeFromMerged  fields whose value comes from the merged card
     */
    public function merge(Customer $keep, Customer $merge, array $takeFromMerged = []): CustomerMerge
    {
        $this->guard($keep, $merge);
        $takeFromMerged = array_values(array_intersect($takeFromMerged, self::FIELDS));

        return DB::transaction(function () use ($keep, $merge, $takeFromMerged) {
            // Lock both cards, the lower id first, so two merges of the same pair cannot cross.
            $ids = [$keep->id, $merge->id];
            sort($ids);
            Customer::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $keep->refresh();
            $merge->refresh();

            $moved = $this->counts($merge);
            $snapshot = [
                ...$merge->only(['code', 'name', 'type', 'source', 'email', 'address', 'birthday', 'gender', 'tax_code', 'invoice_name', 'invoice_address', 'invoice_email', 'tags', 'status', 'note', 'payment_terms_days', 'credit_limit', 'customer_group_id', 'assigned_user_id']),
                'phone' => $merge->phone, 'ulid' => $merge->ulid, 'created_at' => $merge->created_at?->toIso8601String(),
            ];

            // The kept card's own contact is about to change: keep the one that is replaced as a delivery address.
            $replaced = array_intersect($takeFromMerged, ['phone', 'address']);
            if ($replaced !== [] && (filled($keep->address) || filled($keep->phone))) {
                $this->keepAsAddress($keep, $keep->name, $keep->phone, $keep, 'Địa chỉ trước khi gộp');
            }
            foreach ($takeFromMerged as $field) {
                if ($field !== 'phone') {
                    $keep->{$field} = $merge->{$field};
                }
            }
            if (in_array('address', $takeFromMerged, true)) {
                $keep->forceFill(VnAddress::partsOf($merge)); // the standard parts go with the address
            }
            $phone = in_array('phone', $takeFromMerged, true) ? $merge->phone : $keep->phone;
            $mergedPhone = $merge->phone;
            // The phone is unique per business: the duplicate gives it up before the kept card can take it.
            $merge->setPhone(null);
            $merge->saveQuietly();
            if (in_array('phone', $takeFromMerged, true) || blank($keep->phone)) {
                $keep->setPhone($phone ?? $mergedPhone);
            }
            // What is not taken over stays reachable as a delivery address of the kept card.
            if (! in_array('address', $takeFromMerged, true) && (filled($merge->address) || ($mergedPhone && $mergedPhone !== $keep->phone))) {
                $this->keepAsAddress($keep, $merge->name, $mergedPhone, $merge, "Từ khách {$merge->code}");
            }
            $keep->tags = array_values(array_unique([...($keep->tags ?? []), ...($merge->tags ?? [])])) ?: null;
            foreach (['email', 'birthday', 'gender', 'tax_code', 'invoice_name', 'invoice_address', 'invoice_email', 'customer_group_id', 'assigned_user_id', 'source'] as $field) {
                if (blank($keep->{$field}) && filled($merge->{$field})) {
                    $keep->{$field} = $merge->{$field}; // never leave a field empty when the other card had it
                }
            }
            $keep->save();

            foreach (['orders', 'order_payments', 'customer_debt_entries', 'cash_vouchers', 'customer_addresses', 'customer_notes'] as $table) {
                DB::table($table)->where('customer_id', $merge->id)->update(['customer_id' => $keep->id]);
            }
            // A store id belongs to one card only (unique per store), so the duplicate's ids simply move over.
            DB::table('customer_identities')->where('customer_id', $merge->id)->update(['customer_id' => $keep->id]);
            DB::table('customer_merges')->where('customer_id', $merge->id)->update(['customer_id' => $keep->id]);

            $record = CustomerMerge::query()->create([
                'customer_id' => $keep->id, 'merged_customer_id' => $merge->id, 'merged_code' => $merge->code, 'merged_name' => $merge->name,
                'snapshot' => $snapshot, 'moved' => $moved, 'choices' => $takeFromMerged ?: null, 'merged_by' => Auth::id(),
            ]);
            $merge->delete();
            $this->audit->record('customer.merged', $keep, ['merged' => $merge->code, 'moved' => $moved, 'fields' => $takeFromMerged]);

            return $record;
        });
    }

    private function guard(Customer $keep, Customer $merge): void
    {
        if ($keep->id === $merge->id) {
            throw ValidationException::withMessages(['customer' => 'Không thể gộp một khách với chính nó.']);
        }
        if ($keep->tenant_id !== $merge->tenant_id) {
            throw ValidationException::withMessages(['customer' => 'Chỉ gộp được khách của cùng cửa hàng.']);
        }
    }

    /** @return array<string, int> */
    private function counts(Customer $merge): array
    {
        $count = fn (string $table) => DB::table($table)->where('customer_id', $merge->id)->count();

        return [
            'orders' => $count('orders'), 'payments' => $count('order_payments'), 'debt_entries' => $count('customer_debt_entries'),
            'vouchers' => $count('cash_vouchers'), 'addresses' => $count('customer_addresses'), 'notes' => $count('customer_notes'),
            'identities' => $count('customer_identities'),
        ];
    }

    private function value(Customer $customer, string $field): mixed
    {
        // Shown to a person: the group and the staff member by name.
        $value = match ($field) {
            'customer_group_id' => $customer->customerGroup?->name,
            'assigned_user_id' => $customer->assignee?->name,
            default => $customer->{$field},
        };

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    private function keepAsAddress(Customer $keep, string $name, ?string $phone, Customer $from, string $label): void
    {
        $address = $from->address;
        $parts = VnAddress::partsOf($from);
        if (blank($address)) {
            return;
        }
        $exists = $keep->addresses()->get()->contains(fn (CustomerAddress $a) => mb_strtolower(trim($a->address)) === mb_strtolower(trim((string) $address)) && $a->phone === $phone);
        if (! $exists) {
            $keep->addresses()->make(['label' => $label, 'recipient' => $name, 'phone' => $phone, 'address' => $address])->forceFill($parts)->save();
        }
    }
}
