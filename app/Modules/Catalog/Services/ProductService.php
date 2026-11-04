<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Events\SaleStatusChanged;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductComponent;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public const VARIANT_FIELDS = ['sku', 'barcode', 'name', 'options', 'ref_price', 'price_source', 'wholesale_price', 'weight_gram', 'status'];

    public function __construct(private readonly AuditLogger $audit, private readonly InventoryDocumentService $documents) {}

    /**
     * Variants with opening_qty > 0 get their first stock in one opening receipt (same ledger path as a manual
     * receipt, so the weighted average cost starts at opening_cost).
     *
     * @param  array<string, mixed>  $data  validated: product fields + variants[]
     */
    public function create(array $data, ?Warehouse $openingWarehouse = null): Product
    {
        return DB::transaction(function () use ($data, $openingWarehouse) {
            $product = Product::create(collect($data)->only(Product::FIELDS)->all());
            $product->forceFill(['type' => $data['type'] ?? Product::TYPE_STANDARD])->save();
            $opening = [];

            foreach ($data['variants'] as $input) {
                $variant = $product->variants()->create($this->variantAttributes($input));
                if ($product->isCombo()) {
                    $this->syncComponents($variant, $data['components'] ?? []);
                }
                if ((int) ($input['opening_qty'] ?? 0) > 0 && empty($input['conversion'])) {
                    $opening[] = ['variant_id' => $variant->id, 'quantity' => (int) $input['opening_qty'], 'unit_cost' => (string) ($input['opening_cost'] ?? 0)];
                }
            }

            $this->syncConversions($product, $data['variants']);

            if ($opening !== [] && $openingWarehouse !== null) {
                $this->documents->receive($openingWarehouse, $opening, 'Tồn đầu khi tạo sản phẩm', StockReceipt::SOURCE_OPENING);
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
            $product->fill(collect($data)->only(Product::FIELDS)->all())->save();
            $statusChanged = $product->wasChanged('status') ? $product->variants()->pluck('id')->all() : [];

            if ($product->isCombo() && array_key_exists('components', $data)) {
                $this->syncComponents($product->variants()->firstOrFail(), $data['components']);
            }

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
                        if ($variant->wasChanged('status')) {
                            $statusChanged[] = $variant->id;
                        }
                        if ($before !== $variant->only(['sku', 'barcode'])) {
                            $changes[] = ['from' => $before, 'to' => $variant->only(['sku', 'barcode'])];
                        }
                        $kept[] = $variant->id;
                    } else {
                        $kept[] = $product->variants()->create($this->variantAttributes($input))->id;
                    }
                }

                $this->syncConversions($product, $data['variants']);

                foreach ($existing->whereNotIn('id', $kept) as $removed) {
                    $this->deleteVariant($removed);
                }

                if ($changes !== []) {
                    $this->audit->record('product.sku_changed', $product, ['changes' => $changes]);
                }
            }

            $this->saleStatusChanged($statusChanged);

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // Pack sizes first: their base SKU is only free to delete once they are gone.
            foreach ($product->variants()->withCount('components')->get()->sortByDesc('components_count') as $variant) {
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
        return DB::transaction(function () use ($productIds, $status) {
            $changing = Product::query()->whereIn('id', $productIds)->where('status', '!=', $status)->pluck('id')->all();
            $count = Product::query()->whereIn('id', $productIds)->update(['status' => $status]);
            $this->audit->record('product.bulk_status', null, ['count' => $count, 'status' => $status]);
            $this->saleStatusChanged(ProductVariant::query()->whereIn('product_id', $changing)->pluck('id')->all());

            return $count;
        });
    }

    /**
     * The marketplaces publish 0 for a stopped SKU and the real stock again once it is active.
     *
     * @param  array<int, int|string>  $variantIds
     */
    private function saleStatusChanged(array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }
        $tenantId = app(TenantContext::class)->id();
        $ids = array_values(array_unique(array_map('intval', $variantIds)));
        DB::afterCommit(fn () => SaleStatusChanged::dispatch($tenantId, $ids));
    }

    /**
     * Deletes each product on its own: one with stock left is skipped and reported, the others still go.
     *
     * @param  iterable<Product>  $products
     * @return array{deleted: int, skipped: array<int, array{name: string, message: string}>}
     */
    public function bulkDelete(iterable $products): array
    {
        $deleted = 0;
        $skipped = [];

        foreach ($products as $product) {
            try {
                $this->delete($product);
                $deleted++;
            } catch (ValidationException $e) {
                $skipped[] = ['name' => $product->name, 'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Replaces a combo's components. Orders already holding stock keep their reservations (each one records the
     * quantity per combo it was made with); new orders use the new recipe.
     *
     * @param  array<int, array{component_variant_id: int, quantity: int}>  $components
     */
    private function syncComponents(ProductVariant $combo, array $components): void
    {
        $combo->components()->delete();
        foreach ($components as $c) {
            $combo->components()->create($c);
        }
        $this->audit->record('product.combo_components', $combo->product, ['sku' => $combo->sku, 'components' => count($components)]);
    }

    /**
     * Pack sizes (đơn vị quy đổi) of a standard product: a SKU such as "Thùng 24 chai" is stored as a one-component
     * combo of the base SKU, so it holds, ships and publishes stock in base units. Inputs refer to the base by SKU
     * (it may be new in the same request). A variant sent without conversion stops being a pack.
     *
     * @param  array<int, array<string, mixed>>  $inputs
     */
    private function syncConversions(Product $product, array $inputs): void
    {
        if ($product->isCombo()) {
            return;
        }
        $bySku = $product->variants()->with('components')->get()->keyBy(fn (ProductVariant $v) => mb_strtolower($v->sku));

        foreach ($inputs as $input) {
            $variant = $bySku->get(mb_strtolower(ProductVariant::normalizeSku((string) $input['sku'])));
            if ($variant === null) {
                continue;
            }
            $conversion = $input['conversion'] ?? null;
            if ($conversion) {
                $base = $bySku->get(mb_strtolower(trim((string) $conversion['base_sku'])));
                $current = $variant->components->first();
                if ($variant->components->count() !== 1 || $current->component_variant_id !== $base->id || $current->quantity !== (int) $conversion['quantity']) {
                    $this->syncComponents($variant, [['component_variant_id' => $base->id, 'quantity' => (int) $conversion['quantity']]]);
                }
            } elseif ($variant->components->isNotEmpty()) {
                $this->syncComponents($variant, []);
            }
        }
    }

    private function deleteVariant(ProductVariant $variant): void
    {
        $usedIn = ProductComponent::query()->with('combo')->where('component_variant_id', $variant->id)
            ->whereIn('product_variant_id', ProductVariant::query()->select('id'))->first();
        if ($usedIn) {
            throw ValidationException::withMessages(['variants' => "SKU {$variant->sku} đang là thành phần của combo {$usedIn->combo->sku}, không thể xóa."]);
        }

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
        foreach (['ref_price', 'wholesale_price'] as $price) {
            if (array_key_exists($price, $attributes) && $attributes[$price] === null) {
                $attributes[$price] = 0;
            }
        }
        if (array_key_exists('price_source', $attributes) && $attributes['price_source'] === null) {
            unset($attributes['price_source']);
        }
        if (array_key_exists('barcode', $attributes) && $attributes['barcode'] !== null) {
            $attributes['barcode'] = trim($attributes['barcode']) ?: null;
        }

        return $attributes;
    }
}
