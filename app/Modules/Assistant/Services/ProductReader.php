<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Support\Similar;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Tạo sản phẩm từ ảnh": what AI read on product photos, made ready for the product form: category and brand
 * of the shop when one fits, a free SKU, a selling price from the cost (markup, rounded up), variants as option
 * combinations, and a warning when the product looks like one already in the catalogue.
 */
class ProductReader
{
    /** @return list<string> "Group › Sub" paths of the shop's categories, for the AI prompt. */
    public function categoryPaths(): array
    {
        return $this->categories()->pluck('path')->all();
    }

    /** @return Collection<int, array{id: string, path: string, name: string}> */
    private function categories(): Collection
    {
        $all = Category::query()->get(['id', 'ulid', 'name', 'parent_id'])->keyBy('id');
        $path = function (Category $c) use ($all): string {
            $names = [$c->name];
            $p = $c->parent_id ? $all->get($c->parent_id) : null;
            for ($i = 0; $p && $i < 5; $i++) {
                array_unshift($names, $p->name);
                $p = $p->parent_id ? $all->get($p->parent_id) : null;
            }

            return implode(' › ', $names);
        };

        return $all->values()->map(fn (Category $c) => ['id' => $c->ulid, 'path' => $path($c), 'name' => $c->name]);
    }

    /**
     * @param  array<string, mixed>  $read  the AI answer
     * @return list<array<string, mixed>>
     */
    public function suggestions(array $read, int $imageCount, float $markup): array
    {
        $categories = $this->categories();
        $brands = Brand::query()->get(['ulid', 'name']);
        $catalogue = ProductVariant::query()->with('product:id,ulid,name')->limit(20000)->get(['id', 'product_id', 'sku', 'name']);
        $taken = $catalogue->pluck('sku')->map(fn ($s) => mb_strtolower((string) $s))->flip()->all();
        $out = [];
        foreach (array_slice((array) ($read['products'] ?? []), 0, 30) as $raw) {
            $p = (array) $raw;
            $name = Str::limit(trim((string) ($p['name'] ?? '')), 250, '');
            if ($name === '') {
                continue;
            }
            $images = array_values(array_unique(array_filter(array_map('intval', (array) ($p['images'] ?? [])), fn ($i) => $i >= 1 && $i <= $imageCount)));
            $cost = is_numeric($p['cost_price'] ?? null) && (float) $p['cost_price'] > 0 ? (float) $p['cost_price'] : null;
            $catRead = trim((string) ($p['category'] ?? ''));
            $category = $catRead === '' ? null : ($categories->first(fn ($c) => Similar::normalize($c['path']) === Similar::normalize($catRead))
                ?? $categories->first(fn ($c) => Similar::normalize($c['name']) === Similar::normalize((string) Str::afterLast($catRead, '›'))));
            $brandRead = trim((string) ($p['brand'] ?? ''));
            $brand = $brandRead === '' ? null : $brands->first(fn ($b) => Similar::normalize($b->name) === Similar::normalize($brandRead));
            $variants = [];
            foreach (array_slice((array) ($p['variants'] ?? []), 0, 3) as $v) {
                $v = (array) $v;
                $values = array_values(array_filter(array_map(fn ($x) => Str::limit(trim((string) $x), 100, ''), (array) ($v['values'] ?? []))));
                if (trim((string) ($v['name'] ?? '')) !== '' && count($values) > 0) {
                    $variants[] = ['name' => Str::limit(trim((string) $v['name']), 50, ''), 'values' => array_slice($values, 0, 20)];
                }
            }
            $sku = $this->freeSku((string) ($p['sku'] ?? ''), $name, $taken);
            $taken[mb_strtolower($sku)] = true;
            $same = $catalogue->map(fn ($x) => [$x, Similar::score($name, ($x->product->name ?? '').' '.$x->name.' '.$x->sku)])
                ->filter(fn ($x) => $x[1] >= 0.8)->sortByDesc(fn ($x) => $x[1])->first();
            $missing = trim((string) ($p['missing'] ?? ''));

            $out[] = [
                'images' => $images,
                'name' => $name,
                'category' => ['read' => $catRead, 'id' => $category['id'] ?? null, 'path' => $category['path'] ?? null],
                'brand' => ['read' => $brandRead, 'id' => $brand?->ulid],
                'sku' => $sku,
                'warranty_months' => is_numeric($p['warranty_months'] ?? null) ? (int) $p['warranty_months'] : null,
                'weight_gram' => is_numeric($p['weight_grams'] ?? null) && (int) $p['weight_grams'] > 0 ? (int) $p['weight_grams'] : null,
                'cost_price' => $cost,
                'price' => $cost !== null ? self::price($cost, $markup) : null,
                'variants' => $variants,
                'description' => Str::limit(trim((string) ($p['description'] ?? '')), 5000, ''),
                'seen' => Str::limit(trim((string) ($p['seen'] ?? '')), 400, ''),
                'missing' => $missing,
                'duplicate' => $same ? ['product_id' => $same[0]->product?->ulid, 'name' => trim(($same[0]->product->name ?? '').' '.$same[0]->name), 'sku' => $same[0]->sku] : null,
            ];
        }

        return $out;
    }

    /** Cost × (1 + markup %), rounded up to 10.000 đ above 100.000 đ, else to 1.000 đ. */
    public static function price(float $cost, float $markup): float
    {
        $raw = $cost * (1 + $markup / 100);
        $step = $raw >= 100000 ? 10000 : 1000;

        return ceil($raw / $step) * $step;
    }

    /** @param  array<string, mixed>  $taken lower-case SKUs in use */
    private function freeSku(string $read, string $name, array $taken): string
    {
        $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9._\/-]+/', '-', Str::ascii($read)), '-'));
        if ($base === '') {
            $words = Similar::tokens($name);
            $codes = array_filter($words, fn ($w) => preg_match('/\d/', $w) === 1);
            $base = strtoupper($codes !== [] ? implode('-', array_slice($codes, 0, 2)) : implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 4))));
        }
        $base = substr($base !== '' ? $base : 'SP', 0, 40);
        $sku = $base;
        for ($i = 2; isset($taken[mb_strtolower($sku)]) && $i < 1000; $i++) {
            $sku = "{$base}-{$i}";
        }

        return $sku;
    }
}
