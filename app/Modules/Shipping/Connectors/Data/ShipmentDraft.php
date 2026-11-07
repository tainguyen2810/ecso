<?php

namespace App\Modules\Shipping\Connectors\Data;

/**
 * What a carrier needs to price or create one parcel. Built from an order (or from the order form before it is
 * saved). Amounts are VND strings, weight in grams, sizes in cm.
 */
final class ShipmentDraft
{
    public const NOTE_VIEW_NO_TRY = 'view_no_try';

    public const NOTE_TRY = 'try';

    public const NOTE_NO_VIEW = 'no_view';

    public const REQUIRED_NOTES = [
        self::NOTE_VIEW_NO_TRY => 'Cho xem hàng, không cho thử',
        self::NOTE_TRY => 'Cho thử hàng',
        self::NOTE_NO_VIEW => 'Không cho xem hàng',
    ];

    /**
     * @param  array<int, array{name: string, quantity: int, weight_g: int|null}>  $items
     */
    public function __construct(
        public readonly ?int $warehouseId,
        public readonly ?string $toName,
        public readonly ?string $toPhone,
        public readonly ?string $toStreet,
        public readonly ?string $toAddress,
        public readonly ?string $toProvinceCode,
        public readonly ?string $toWardCode,
        public readonly int $weightG,
        public readonly ?int $lengthCm = null,
        public readonly ?int $widthCm = null,
        public readonly ?int $heightCm = null,
        public readonly string $codAmount = '0',
        public readonly string $insuredValue = '0',
        public readonly string $payer = 'shop',
        public readonly string $requiredNote = self::NOTE_VIEW_NO_TRY,
        public readonly ?string $note = null,
        public readonly array $items = [],
        public readonly ?string $pickupShift = null,
    ) {}

    /** What is kept on the shipment so the parcel can be made again the same way (retry). */
    public function options(): array
    {
        return [
            'length_cm' => $this->lengthCm, 'width_cm' => $this->widthCm, 'height_cm' => $this->heightCm,
            'insured_value' => $this->insuredValue, 'required_note' => $this->requiredNote, 'note' => $this->note,
            'pickup_shift' => $this->pickupShift,
        ];
    }
}
