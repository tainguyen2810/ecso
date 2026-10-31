<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
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
            'variants' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'variants.*.id' => ['sometimes', 'string'],
            'variants.*.sku' => ['required', 'string', 'max:64', 'regex:'.self::SKU_REGEX, 'distinct:ignore_case'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64', 'distinct:ignore_case'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.options' => ['nullable', 'array', 'max:5'],
            'variants.*.options.*' => ['string', 'max:100'],
            'variants.*.ref_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.weight_gram' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
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
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['category_id' => Category::class, 'brand_id' => Brand::class] as $key => $model) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? $model::query()->where('ulid', $data[$key])->value('id') ?? abort(422, 'Danh mục hoặc thương hiệu không hợp lệ.') : null;
            }
        }

        return $data;
    }
}
