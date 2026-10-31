<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public const VARIANT_FIELDS = ['sku', 'barcode', 'name', 'options', 'ref_price', 'weight_gram', 'status'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated: product fields + variants[]
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create(collect($data)->only(['name', 'category_id', 'brand_id', 'description', 'status'])->all());

            foreach ($data['variants'] as $variant) {
                $product->variants()->create($this->variantAttributes($variant));
            }

            $this->audit->record('product.created', $product, ['name' => $product->name, 'skus' => array_column($data['variants'], 'sku')]);

            return $product;
        });
    }

    /**
     * Variants with an id are updated, without an id created; variants left out are removed (soft delete),
     * which is refused while the variant still has stock or reservations.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill(collect($data)->only(['name', 'category_id', 'brand_id', 'description', 'status'])->all())->save();

            if (array_key_exists('variants', $data)) {
                $existing = $product->variants()->get()->keyBy('ulid');
                $kept = [];
                $changes = [];

                foreach ($data['variants'] as $input) {
                    $variant = isset($input['id']) ? $existing->get($input['id']) : null;

                    if (isset($input['id']) && $variant === null) {
                        throw ValidationException::withMessages(['variants' => 'Biến thể không thuộc sản phẩm này.']);
                    }

                    if ($variant) {
                        $before = $variant->only(['sku', 'barcode']);
                        $variant->fill($this->variantAttributes($input))->save();
                        if ($before !== $variant->only(['sku', 'barcode'])) {
                            $changes[] = ['from' => $before, 'to' => $variant->only(['sku', 'barcode'])];
                        }
                        $kept[] = $variant->id;
                    } else {
                        $kept[] = $product->variants()->create($this->variantAttributes($input))->id;
                    }
                }

                foreach ($existing->whereNotIn('id', $kept) as $removed) {
                    $this->deleteVariant($removed);
                }

                if ($changes !== []) {
                    $this->audit->record('product.sku_changed', $product, ['changes' => $changes]);
                }
            }

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            foreach ($product->variants()->get() as $variant) {
                $this->deleteVariant($variant);
            }
            $product->delete();
            $this->audit->record('product.deleted', $product, ['name' => $product->name]);
        });
    }

    /**
     * @param  array<int, int>  $productIds
     */
    public function bulkStatus(array $productIds, string $status): int
    {
        $count = Product::query()->whereIn('id', $productIds)->update(['status' => $status]);
        $this->audit->record('product.bulk_status', null, ['count' => $count, 'status' => $status]);

        return $count;
    }

    private function deleteVariant(ProductVariant $variant): void
    {
        $hasStock = DB::table('stock_balances')
            ->where('tenant_id', $variant->tenant_id)
            ->where('product_variant_id', $variant->id)
            ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0))
            ->exists();

        if ($hasStock) {
            throw ValidationException::withMessages(['variants' => "SKU {$variant->sku} còn tồn kho hoặc đang được giữ, không thể xóa."]);
        }

        $variant->delete();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function variantAttributes(array $input): array
    {
        $attributes = collect($input)->only(self::VARIANT_FIELDS)->all();
        if (isset($attributes['sku'])) {
            $attributes['sku'] = ProductVariant::normalizeSku($attributes['sku']);
        }
        // Optional in the form, NOT NULL DEFAULT 0 in the table.
        if (array_key_exists('ref_price', $attributes) && $attributes['ref_price'] === null) {
            $attributes['ref_price'] = 0;
        }
        if (array_key_exists('barcode', $attributes) && $attributes['barcode'] !== null) {
            $attributes['barcode'] = trim($attributes['barcode']) ?: null;
        }

        return $attributes;
    }
}
