<?php

namespace App\Modules\Sales\Promotions;

use App\Modules\Sales\Models\Promotion;

/** What an order receives: money off (all promotions together), gift lines, and why a typed code did not work. */
final class PromotionResult
{
    public string $discount = '0.00';

    /** @var array<int, array{promotion: Promotion, amount: string, gifts: array<int, array{variant_id: int, quantity: int}>}> */
    public array $applied = [];

    public ?string $codeError = null;

    /** @param  array{promotion: Promotion, amount: string, gifts: array<int, array{variant_id: int, quantity: int}>}  $offer */
    public function add(array $offer): void
    {
        $this->applied[] = $offer;
        if ($offer['promotion']->type !== Promotion::GIFT) {
            $this->discount = bcadd($this->discount, $offer['amount'], 2);
        }
    }

    /** The code of the promotion received by code, if any. */
    public function code(): ?string
    {
        foreach ($this->applied as $a) {
            if ($a['promotion']->code !== null) {
                return $a['promotion']->code;
            }
        }

        return null;
    }

    /** @return array<int, array{variant_id: int, quantity: int, promotion_id: int}> */
    public function gifts(): array
    {
        $gifts = [];
        foreach ($this->applied as $a) {
            foreach ($a['gifts'] as $g) {
                $gifts[] = $g + ['promotion_id' => $a['promotion']->id];
            }
        }

        return $gifts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'discount' => $this->discount,
            'applied' => array_map(fn (array $a) => [
                'id' => $a['promotion']->ulid,
                'name' => $a['promotion']->name,
                'type' => $a['promotion']->type,
                'code' => $a['promotion']->code,
                'amount' => $a['amount'],
                'gifts' => array_map(fn ($g) => ['quantity' => $g['quantity']], $a['gifts']),
            ], $this->applied),
            'code_error' => $this->codeError,
        ];
    }
}
