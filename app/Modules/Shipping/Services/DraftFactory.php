<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Models\Order;
use App\Modules\Shipping\Connectors\Data\ShipmentDraft;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Builds the parcel a carrier is asked to price or carry: from a saved order (with what the shop changed in the
 * dialog), or from the order form before the order exists. What the shop typed always wins over what is derived.
 */
class DraftFactory
{
    /** Validation of what the shop may change about the parcel. */
    public static function rules(): array
    {
        return [
            'weight_g' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'length_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'width_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'insured_value' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'payer' => ['nullable', Rule::in(['shop', 'buyer'])],
            'required_note' => ['nullable', Rule::in(array_keys(ShipmentDraft::REQUIRED_NOTES))],
            'note' => ['nullable', 'string', 'max:500'],
            'pickup_shift' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** The order form's parcel, before the order is saved. */
    public static function draftRules(): array
    {
        return [
            ...self::rules(),
            'warehouse_id' => ['nullable', 'string', 'size:26'],
            'province_code' => ['nullable', 'string', 'max:10'],
            'ward_code' => ['nullable', 'string', 'max:10'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.variant_id' => ['nullable', 'string', 'size:26'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function fromOrder(Order $order, array $in = []): ShipmentDraft
    {
        $order->loadMissing('items.variant');
        $items = $order->items->map(fn ($i) => ['name' => mb_substr(trim($i->name.' '.($i->variant_name ?? '')), 0, 200), 'quantity' => (int) $i->quantity, 'weight_g' => $i->variant?->weight_gram ?: null])->all();
        $due = $order->ownsCustomerData() ? $order->balanceDue() : (string) $order->total;

        return $this->make($in, [
            'warehouse_id' => $order->warehouse_id,
            'to_name' => $order->buyer_name,
            'to_phone' => $order->buyer_phone,
            'to_street' => $order->shipping_street,
            'to_address' => $order->shipping_address,
            'to_province_code' => $order->shipping_province_code,
            'to_ward_code' => $order->shipping_ward_code,
            'cod_amount' => $order->is_cod && bccomp($due, '0', 2) > 0 ? $due : '0',
            'insured_value' => (string) $order->total,
            'items' => $items,
        ]);
    }

    /** From the order form: warehouse ulid, address codes and lines (variant ulids). */
    public function fromInput(array $in): ShipmentDraft
    {
        $warehouseId = filled($in['warehouse_id'] ?? null) ? Warehouse::query()->where('ulid', $in['warehouse_id'])->value('id') : null;
        if (filled($in['warehouse_id'] ?? null) && $warehouseId === null) {
            throw ValidationException::withMessages(['warehouse_id' => 'Kho không tồn tại.']);
        }
        $variants = ProductVariant::query()->whereIn('ulid', array_filter(array_column($in['items'] ?? [], 'variant_id')))->pluck('weight_gram', 'ulid');
        $items = array_map(fn ($i) => ['name' => 'Hàng hóa', 'quantity' => (int) ($i['quantity'] ?? 1), 'weight_g' => $variants[$i['variant_id'] ?? ''] ?? null], $in['items'] ?? []);

        return $this->make($in, [
            'warehouse_id' => $warehouseId,
            'to_province_code' => $in['province_code'] ?? null,
            'to_ward_code' => $in['ward_code'] ?? null,
            'cod_amount' => (string) ($in['cod_amount'] ?? '0'),
            'insured_value' => (string) ($in['insured_value'] ?? $in['cod_amount'] ?? '0'),
            'items' => $items,
        ]);
    }

    private function make(array $in, array $base): ShipmentDraft
    {
        $itemsWeight = array_sum(array_map(fn ($i) => (int) ($i['weight_g'] ?? 0) * (int) $i['quantity'], $base['items']));
        $missingWeight = collect($base['items'])->contains(fn ($i) => empty($i['weight_g']));
        $weight = (int) ($in['weight_g'] ?? 0) ?: (! $missingWeight && $itemsWeight > 0 ? $itemsWeight : (int) config('shipping.default_weight_g'));
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        return new ShipmentDraft(
            warehouseId: $base['warehouse_id'],
            toName: $base['to_name'] ?? null,
            toPhone: $base['to_phone'] ?? null,
            toStreet: $base['to_street'] ?? null,
            toAddress: $base['to_address'] ?? null,
            toProvinceCode: $base['to_province_code'],
            toWardCode: $base['to_ward_code'],
            weightG: $weight,
            lengthCm: isset($in['length_cm']) ? (int) $in['length_cm'] : null,
            widthCm: isset($in['width_cm']) ? (int) $in['width_cm'] : null,
            heightCm: isset($in['height_cm']) ? (int) $in['height_cm'] : null,
            codAmount: $money(array_key_exists('cod_amount', $in) && $in['cod_amount'] !== null ? $in['cod_amount'] : $base['cod_amount']),
            insuredValue: $money(array_key_exists('insured_value', $in) && $in['insured_value'] !== null ? $in['insured_value'] : $base['insured_value']),
            payer: $in['payer'] ?? 'shop',
            requiredNote: $in['required_note'] ?? ShipmentDraft::NOTE_VIEW_NO_TRY,
            note: $in['note'] ?? null,
            items: $base['items'],
            pickupShift: $in['pickup_shift'] ?? null,
        );
    }
}
