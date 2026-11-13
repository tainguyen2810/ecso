<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public const SKU_REGEX = '/^[\pL\pN._\-\/]+$/u';

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::in(Product::STATUSES)],
            'category_id' => ['nullable', 'string'],
            'brand_id' => ['nullable', 'string'],
            'unit' => ['nullable', 'string', 'max:30'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Opening stock, only when creating: one opening receipt in this warehouse.
            'opening_warehouse_id' => $creating ? ['nullable', 'string', 'required_with:variants.*.opening_qty'] : ['prohibited'],
            'variants.*.opening_qty' => $creating ? ['nullable', 'integer', 'min:0', 'max:1000000'] : ['prohibited'],
            'variants.*.opening_cost' => $creating ? ['nullable', 'numeric', 'min:0', 'max:9999999999'] : ['prohibited'],
            'variants' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'variants.*.id' => ['sometimes', 'string'],
            'variants.*.sku' => ['required', 'string', 'max:64', $this->skuFormat(), 'distinct:ignore_case'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64', 'distinct:ignore_case'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.options' => ['nullable', 'array', 'max:5'],
            'variants.*.options.*' => ['string', 'max:100'],
            'variants.*.ref_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.price_source' => ['nullable', 'in:auto,ecso,woo'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.weight_gram' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // Usual supplier (ULID) and the supplier's code; package size in millimetres.
            'variants.*.supplier_id' => ['nullable', 'string'],
            'variants.*.supplier_sku' => ['nullable', 'string', 'max:64'],
            'variants.*.location' => ['nullable', 'string', 'max:50'],
            'variants.*.length_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variants.*.width_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variants.*.height_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variants.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // Pack size (đơn vị quy đổi): this SKU = quantity × the base SKU of the same product.
            'variants.*.conversion' => ['nullable', 'array'],
            'variants.*.conversion.base_sku' => ['required_with:variants.*.conversion', 'string', 'max:64'],
            'variants.*.conversion.quantity' => ['required_with:variants.*.conversion', 'integer', 'min:2', 'max:100000'],
            // Combos: the type is chosen when creating; a combo has one SKU and its components.
            'type' => $creating ? ['sometimes', Rule::in([Product::TYPE_STANDARD, Product::TYPE_COMBO])] : ['prohibited'],
            'components' => ['sometimes', 'array', 'max:30'],
            'components.*.variant_id' => ['required', 'string', 'distinct'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * New or changed SKUs use letters, digits and . _ - /. A SKU kept as it is may have other characters: SKUs
     * created from a website (spaces, #, +) must not stop the product from being edited.
     */
    private function skuFormat(): Closure
    {
        $kept = null;

        return function (string $attribute, mixed $value, Closure $fail) use (&$kept) {
            if (! is_string($value) || preg_match(self::SKU_REGEX, $value)) {
                return;
            }
            $product = $this->route('product');
            if ($product instanceof Product) {
                $kept ??= ProductVariant::query()->where('product_id', $product->id)->pluck('sku', 'ulid')->all();
                $index = (int) explode('.', $attribute)[1];
                $id = $this->input("variants.{$index}.id");
                if (is_string($id) && isset($kept[$id]) && $kept[$id] === $value) {
                    return;
                }
            }
            $fail('SKU chỉ gồm chữ, số và . _ - /');
        };
    }

    public function isCombo(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product->isCombo() : $this->input('type') === Product::TYPE_COMBO;
    }

    /**
     * Components must be existing, non-combo SKUs of this business (a combo cannot contain itself or another combo).
     */
    private function checkCombo(Validator $validator): void
    {
        if (! $this->isCombo()) {
            if ($this->has('components')) {
                $validator->errors()->add('components', 'Chỉ sản phẩm combo mới có thành phần.');
            }

            return;
        }
        if (count($this->input('variants', [1])) !== 1) {
            $validator->errors()->add('variants', 'Combo chỉ có một SKU.');
        }
        foreach (['opening_qty', 'opening_cost'] as $field) {
            if (collect($this->input('variants', []))->contains(fn ($v) => ! empty($v[$field]))) {
                $validator->errors()->add('opening_warehouse_id', 'Combo không có tồn riêng: nhập tồn cho các SKU thành phần.');
            }
        }
        $components = $this->input('components');
        if ($components === null && $this->route('product') instanceof Product) {
            return; // components unchanged
        }
        if (empty($components)) {
            $validator->errors()->add('components', 'Combo cần ít nhất một SKU thành phần.');

            return;
        }

        $found = ProductVariant::query()->with('product')->withCount('components')->whereIn('ulid', array_column($components, 'variant_id'))->get()->keyBy('ulid');
        foreach ($components as $i => $c) {
            $variant = $found->get($c['variant_id']);
            if ($variant === null) {
                $validator->errors()->add("components.{$i}.variant_id", 'SKU không tồn tại.');
            } elseif ($variant->product?->isCombo()) {
                $validator->errors()->add("components.{$i}.variant_id", "SKU {$variant->sku} là combo, không dùng làm thành phần.");
            } elseif ($variant->components_count > 0) {
                $validator->errors()->add("components.{$i}.variant_id", "SKU {$variant->sku} là đơn vị quy đổi: chọn SKU đơn vị gốc.");
            }
        }
    }

    public function messages(): array
    {
        return [
            'variants.*.sku.regex' => 'SKU chỉ gồm chữ, số và . _ - /',
            'variants.*.sku.distinct' => 'SKU bị trùng trong cùng sản phẩm.',
            'variants.*.barcode.distinct' => 'Barcode bị trùng trong cùng sản phẩm.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->checkUnique($validator, 'sku');
            $this->checkUnique($validator, 'barcode');
            $this->checkCombo($validator);
            $this->checkConversions($validator);
        }];
    }

    /**
     * SKU and barcode are unique per tenant, including soft-deleted variants (the DB index covers them too).
     */
    private function checkUnique(Validator $validator, string $field): void
    {
        $variants = $this->input('variants', []);
        $values = collect($variants)->pluck($field)->filter()->map(fn ($v) => trim((string) $v));
        if ($values->isEmpty()) {
            return;
        }

        $product = $this->route('product');
        $taken = ProductVariant::query()->withTrashed()->whereIn($field, $values->all())->get(['ulid', 'product_id', $field]);

        foreach ($variants as $i => $variant) {
            $value = trim((string) ($variant[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $conflict = $taken->first(fn ($t) => mb_strtolower($t->{$field}) === mb_strtolower($value)
                // Keeping its own value is not a conflict.
                && ! ($product instanceof Product && $t->product_id === $product->id && ($variant['id'] ?? null) === $t->ulid));

            if ($conflict) {
                $label = $field === 'sku' ? 'SKU' : 'Barcode';
                $validator->errors()->add("variants.{$i}.{$field}", "{$label} {$value} đã tồn tại.");
            }
        }
    }

    /**
     * A pack size points at a base SKU of the same request that is not itself a pack.
     */
    private function checkConversions(Validator $validator): void
    {
        $variants = $this->input('variants', []);
        $plain = [];
        foreach ($variants as $v) {
            if (empty($v['conversion'])) {
                $plain[mb_strtolower(trim((string) ($v['sku'] ?? '')))] = true;
            }
        }
        foreach ($variants as $i => $v) {
            if (empty($v['conversion'])) {
                continue;
            }
            if ($this->isCombo()) {
                $validator->errors()->add("variants.{$i}.conversion", 'Combo không có đơn vị quy đổi.');
            } elseif (! isset($plain[mb_strtolower(trim((string) $v['conversion']['base_sku']))])) {
                $validator->errors()->add("variants.{$i}.conversion.base_sku", 'Chọn SKU đơn vị gốc trong cùng sản phẩm.');
            }
            if (! empty($v['opening_qty'])) {
                $validator->errors()->add("variants.{$i}.opening_qty", 'Nhập tồn đầu cho SKU đơn vị gốc.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (array_key_exists('tags', $data)) {
            $tags = collect($data['tags'] ?? [])->map(fn ($t) => trim($t))->filter()->unique(fn ($t) => mb_strtolower($t))->values()->all();
            $data['tags'] = $tags ?: null;
        }
        if (array_key_exists('unit', $data)) {
            $data['unit'] = trim((string) $data['unit']) ?: null;
        }

        foreach (['category_id' => Category::class, 'brand_id' => Brand::class] as $key => $model) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? $model::query()->where('ulid', $data[$key])->value('id') ?? abort(422, 'Danh mục hoặc thương hiệu không hợp lệ.') : null;
            }
        }

        // Usual supplier: a supplier of this business, by ULID.
        $supplierUlids = collect($data['variants'] ?? [])->pluck('supplier_id')->filter()->unique()->values()->all();
        if ($supplierUlids !== []) {
            $suppliers = DB::table('suppliers')->where('tenant_id', app(TenantContext::class)->id())->whereIn('ulid', $supplierUlids)->pluck('id', 'ulid');
            foreach ($data['variants'] as $i => $v) {
                if (! empty($v['supplier_id'])) {
                    $data['variants'][$i]['supplier_id'] = $suppliers[$v['supplier_id']] ?? abort(422, 'Nhà cung cấp không hợp lệ.');
                }
            }
        }

        if (isset($data['components'])) {
            $ids = ProductVariant::query()->whereIn('ulid', array_column($data['components'], 'variant_id'))->pluck('id', 'ulid');
            $data['components'] = array_map(fn ($c) => ['component_variant_id' => $ids[$c['variant_id']], 'quantity' => (int) $c['quantity']], $data['components']);
        }

        return $data;
    }
}
