<?php

namespace App\Modules\Inventory\Http\Controllers\Concerns;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\ValidationException;

trait ResolvesLines
{
    /**
     * Maps public variant ULIDs to internal ids inside the current tenant. Unknown ULIDs (including another
     * tenant's) are a validation error, never silently dropped.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function resolveLines(array $lines): array
    {
        $ids = ProductVariant::query()->whereIn('ulid', array_column($lines, 'variant_id'))->pluck('id', 'ulid');

        foreach ($lines as $i => $line) {
            if (! isset($ids[$line['variant_id']])) {
                throw ValidationException::withMessages(["lines.{$i}.variant_id" => 'SKU không tồn tại.']);
            }
            $lines[$i]['variant_id'] = $ids[$line['variant_id']];
        }

        return array_values($lines);
    }

    protected function findWarehouse(string $ulid, string $field = 'warehouse_id'): Warehouse
    {
        return Warehouse::query()->where('ulid', $ulid)->first()
            ?? throw ValidationException::withMessages([$field => 'Kho không tồn tại.']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function lineRules(int $max = 500): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', "max:{$max}"],
            'lines.*.variant_id' => ['required', 'string', 'distinct'],
        ];
    }
}
