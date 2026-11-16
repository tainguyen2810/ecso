<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Shared\Support\ImportHeaders;

/**
 * Reads the file the "Lấy dữ liệu Sapo" bookmark saves from a Sapo GO admin page (see public/sapo-go-export.js) into
 * the rows ProductImporter imports. Sapo GO gives shops no API key, so the bookmark reads the product list the Sapo
 * page itself loads, while the user is signed in there, and EzSell never sees the Sapo password.
 *
 * The file holds what an Excel export does not: the stock actually on hand and the moving average cost in each
 * branch, picture links, combos with their components and pack sizes (đơn vị quy đổi) with their base SKU.
 */
class SapoGoReader
{
    public const FORMAT = 'ezsell-sapo-go';

    private const DEFAULT_OPTION = 'mặc định';

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: list<array{row: int, message: string}>, source: string, columns: list<array{header: string, key: string|null}>, branches: array<string, int>}
     */
    public function read(string $path): array
    {
        $empty = ['rows' => [], 'source' => ImportHeaders::SAPO, 'columns' => [], 'branches' => []];
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['products'] ?? null)) {
            return $empty + ['errors' => [['row' => 1, 'message' => 'File không phải dữ liệu lấy từ Sapo bằng nút "Lấy dữ liệu Sapo".']]];
        }

        $locations = [];
        foreach ((array) ($data['locations'] ?? []) as $location) {
            $locations[(string) ($location['id'] ?? '')] = trim((string) ($location['name'] ?? '')) ?: 'Chi nhánh '.($location['id'] ?? '');
        }

        // Pack sizes are kept with the product of their base SKU, as EzSell stores them.
        $productOfSku = [];
        foreach ($data['products'] as $product) {
            foreach ((array) ($product['variants'] ?? []) as $variant) {
                $productOfSku[mb_strtolower(trim((string) ($variant['sku'] ?? '')))] = $product;
            }
        }

        $rows = [];
        $line = 0;
        foreach ($data['products'] as $product) {
            $variants = array_values((array) ($product['variants'] ?? []));
            $combo = ($product['product_type'] ?? '') === 'composite';
            $productImages = $this->strings($product['images'] ?? []);
            $baseUnit = '';
            foreach ($variants as $variant) {
                if (empty($variant['packsize'])) {
                    $baseUnit = trim((string) ($variant['unit'] ?? ''));
                    break;
                }
            }

            foreach ($variants as $index => $variant) {
                $line++;
                $options = array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($variant['opts'] ?? [])), fn ($o) => $o !== '' && mb_strtolower($o) !== self::DEFAULT_OPTION));
                $pack = ! empty($variant['packsize']) && filled($variant['packsize_root_sku'] ?? null);
                $owner = $pack ? ($productOfSku[mb_strtolower(trim((string) $variant['packsize_root_sku']))] ?? $product) : $product;
                $weight = (float) ($variant['weight_value'] ?? 0);

                $row = [
                    'product_key' => 'sapo:'.($owner['id'] ?? $product['id'] ?? $line),
                    'product_name' => trim((string) ($owner['name'] ?? '')),
                    'sku' => trim((string) ($variant['sku'] ?? '')),
                    'variant_name' => implode(' / ', $options) ?: ($pack ? trim((string) ($variant['unit'] ?? '')) : ''),
                    'barcode' => trim((string) ($variant['barcode'] ?? '')),
                    'category' => trim((string) ($product['category'] ?? '')),
                    'brand' => trim((string) ($product['brand'] ?? '')),
                    'ref_price' => $this->money($variant['retail'] ?? null),
                    'wholesale_price' => $this->money($variant['wholesale'] ?? null),
                    'opening_cost' => $this->money($variant['import'] ?? null),
                    'opening_qty' => '',
                    'weight_gram' => $weight > 0 ? (string) (int) round($weight * (mb_strtolower((string) ($variant['weight_unit'] ?? 'g')) === 'kg' ? 1000 : 1)) : '',
                    'status' => ($product['status'] ?? 'active') === 'active' && ($variant['sellable'] ?? true) !== false && ($variant['status'] ?? 'active') === 'active' ? Product::STATUS_ACTIVE : 'inactive',
                    'unit' => $pack ? '' : $baseUnit,
                    'description' => $index === 0 ? (string) ($product['description'] ?? '') : '',
                    'tags' => is_array($product['tags'] ?? null) ? implode(',', $this->strings($product['tags'])) : (string) ($product['tags'] ?? ''),
                    '_from' => ImportHeaders::SAPO,
                    'image_urls' => implode(' ', array_merge($index === 0 ? $productImages : [], $this->strings($variant['images'] ?? []))),
                ];

                if ($combo) {
                    $row['_type'] = Product::TYPE_COMBO;
                    $row['_components'] = array_map(fn ($c) => ['sku' => trim((string) ($c['sku'] ?? '')), 'quantity' => (int) ($c['quantity'] ?? 0)], (array) ($variant['composite_items'] ?? []));
                } elseif ($pack) {
                    $row['_components'] = [['sku' => trim((string) $variant['packsize_root_sku']), 'quantity' => (int) ($variant['packsize_quantity'] ?? 0)]];
                } else {
                    $row['_stock'] = [];
                    foreach ((array) ($variant['stock'] ?? []) as $stock) {
                        $name = $locations[(string) ($stock['location_id'] ?? '')] ?? 'Chi nhánh '.($stock['location_id'] ?? '');
                        $qty = (float) ($stock['on_hand'] ?? 0);
                        $row['_stock'][$name] = ['qty' => $qty > 0 ? (string) (int) floor($qty) : '', 'cost' => $this->money($stock['mac'] ?? null)];
                    }
                }
                $rows[$line] = $row;
            }
        }

        $empty['rows'] = $rows;
        $empty['branches'] = ProductImporter::branchTotals($rows);

        return $empty + ['errors' => $rows === [] ? [['row' => 1, 'message' => 'File không có sản phẩm nào.']] : []];
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) && (float) $value > 0 ? (string) round((float) $value, 2) : '';
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return array_values(array_filter(array_map(fn ($v) => trim((string) (is_array($v) ? ($v['src'] ?? $v['full_path'] ?? '') : $v)), (array) $values)));
    }
}
