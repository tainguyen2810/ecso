<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Imports products from CSV/XLSX.
 *
 * Matching rules (no guessing):
 *  - A row whose SKU already exists updates that variant (exact SKU match only).
 *  - Rows sharing the same non-empty `product_key` become variants of one new product.
 *    A row without product_key becomes its own product. Products are never matched by name.
 *  - All rows are validated first; if any row is invalid nothing is written (all-or-nothing).
 *  - Optional opening_qty/opening_cost create one opening receipt in the chosen warehouse
 *    (only for SKUs created by this import, so re-importing a file cannot add stock twice).
 */
class ProductImporter
{
    public const COLUMNS = ['product_key', 'product_name', 'sku', 'variant_name', 'barcode', 'category', 'brand', 'ref_price', 'weight_gram', 'status', 'opening_qty', 'opening_cost'];

    public const MAX_ROWS = 20000;

    public function __construct(private readonly InventoryDocumentService $documents, private readonly AuditLogger $audit) {}

    /**
     * @return array{rows: array<int, array<string, string>>, errors: array<int, array{row: int, message: string}>}
     */
    public function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);

        $rows = [];
        $errors = [];
        $header = null;
        $line = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $row->toArray());

                if ($header === null) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '');
                    $header = array_map(fn ($h) => strtolower(trim($h)), $cells);
                    foreach (['product_name', 'sku'] as $required) {
                        if (! in_array($required, $header, true)) {
                            $errors[] = ['row' => 1, 'message' => "Thiếu cột bắt buộc: {$required}"];
                        }
                    }
                    if ($errors) {
                        break 2;
                    }

                    continue;
                }

                if (implode('', $cells) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $errors[] = ['row' => $line, 'message' => 'Tệp vượt quá '.self::MAX_ROWS.' dòng.'];
                    break 2;
                }

                $assoc = [];
                foreach (self::COLUMNS as $column) {
                    $index = array_search($column, $header, true);
                    $assoc[$column] = $index === false ? '' : ($cells[$index] ?? '');
                }
                $rows[$line] = $assoc;
            }
            break; // first sheet only
        }

        $reader->close();

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  array<int, array<string, string>>  $rows  keyed by file line number
     * @return array{created: int, updated: int, products: int, errors: array<int, array{row: int, message: string}>, opening_receipt: string|null}
     */
    public function import(array $rows, bool $dryRun = false, ?Warehouse $openingWarehouse = null): array
    {
        $existing = ProductVariant::query()->withTrashed()->get(['id', 'sku', 'barcode', 'product_id', 'deleted_at'])
            ->keyBy(fn ($v) => mb_strtolower($v->sku));
        $barcodes = $existing->filter(fn ($v) => filled($v->barcode))->mapWithKeys(fn ($v) => [mb_strtolower($v->barcode) => mb_strtolower($v->sku)]);

        $errors = $this->validate($rows, $existing, $barcodes, $openingWarehouse);
        $creates = 0;
        $updates = 0;
        foreach ($rows as $row) {
            isset($existing[mb_strtolower($row['sku'])]) ? $updates++ : $creates++;
        }

        if ($errors || $dryRun) {
            return ['created' => $errors ? 0 : $creates, 'updated' => $errors ? 0 : $updates, 'products' => 0, 'errors' => $errors, 'opening_receipt' => null, 'dry_run' => $dryRun];
        }

        return DB::transaction(function () use ($rows, $existing, $openingWarehouse, $creates, $updates) {
            $categories = Category::query()->whereNull('parent_id')->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
            $brands = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
            $productsByKey = [];
            $productCount = 0;
            $opening = [];

            foreach ($rows as $row) {
                $categoryId = $this->lookup($categories, $row['category'], fn ($name) => Category::create(['name' => $name])->id);
                $brandId = $this->lookup($brands, $row['brand'], fn ($name) => Brand::create(['name' => $name])->id);
                $variantData = array_filter([
                    'name' => $row['variant_name'] ?: null,
                    'barcode' => $row['barcode'] ?: null,
                    'ref_price' => $row['ref_price'] !== '' ? $row['ref_price'] : null,
                    'weight_gram' => $row['weight_gram'] !== '' ? (int) $row['weight_gram'] : null,
                    'status' => $row['status'] ?: null,
                ], fn ($v) => $v !== null);

                $match = $existing[mb_strtolower($row['sku'])] ?? null;
                if ($match) {
                    ProductVariant::query()->withTrashed()->whereKey($match->id)->update($variantData);
                    Product::query()->withTrashed()->whereKey($match->product_id)->update(array_filter([
                        'name' => $row['product_name'], 'category_id' => $categoryId, 'brand_id' => $brandId,
                    ]));

                    continue;
                }

                $key = $row['product_key'] !== '' ? mb_strtolower($row['product_key']) : null;
                $product = $key !== null ? ($productsByKey[$key] ?? null) : null;
                if ($product === null) {
                    $product = Product::create(['name' => $row['product_name'], 'category_id' => $categoryId, 'brand_id' => $brandId, 'status' => Product::STATUS_ACTIVE]);
                    $productCount++;
                    if ($key !== null) {
                        $productsByKey[$key] = $product;
                    }
                }

                $variant = $product->variants()->create(['sku' => ProductVariant::normalizeSku($row['sku'])] + $variantData);

                if ((int) $row['opening_qty'] > 0) {
                    $opening[] = ['variant_id' => $variant->id, 'quantity' => (int) $row['opening_qty'], 'unit_cost' => $row['opening_cost'] !== '' ? $row['opening_cost'] : '0'];
                }
            }

            $receipt = null;
            if ($opening && $openingWarehouse) {
                $receipt = $this->documents->receive($openingWarehouse, $opening, 'Tồn đầu kỳ từ import sản phẩm', StockReceipt::SOURCE_OPENING);
            }

            $this->audit->record('product.imported', null, ['created' => $creates, 'updated' => $updates, 'products' => $productCount]);

            return ['created' => $creates, 'updated' => $updates, 'products' => $productCount, 'errors' => [], 'opening_receipt' => $receipt?->number, 'dry_run' => false];
        });
    }

    /**
     * @return array<int, array{row: int, message: string}>
     */
    private function validate(array $rows, $existing, $barcodes, ?Warehouse $openingWarehouse): array
    {
        $errors = [];
        $skusInFile = [];
        $barcodesInFile = [];
        $add = function (int $line, string $message) use (&$errors) {
            $errors[] = ['row' => $line, 'message' => $message];
        };

        foreach ($rows as $line => $row) {
            $sku = $row['sku'];
            $lower = mb_strtolower($sku);

            if ($sku === '') {
                $add($line, 'Thiếu SKU.');
            } elseif (mb_strlen($sku) > 64 || ! preg_match('/^[\pL\pN._\-\/]+$/u', $sku)) {
                $add($line, "SKU \"{$sku}\" không hợp lệ (tối đa 64 ký tự: chữ, số, . _ - /).");
            } elseif (isset($skusInFile[$lower])) {
                $add($line, "SKU {$sku} bị trùng với dòng {$skusInFile[$lower]}.");
            } else {
                $skusInFile[$lower] = $line;
            }

            $match = $existing[$lower] ?? null;
            if ($match?->deleted_at) {
                $add($line, "SKU {$sku} thuộc một sản phẩm đã xóa.");
            }

            if ($row['product_name'] === '' && ! $match) {
                $add($line, 'Thiếu tên sản phẩm.');
            }
            if (mb_strlen($row['product_name']) > 255) {
                $add($line, 'Tên sản phẩm quá dài.');
            }

            if ($row['barcode'] !== '') {
                $b = mb_strtolower($row['barcode']);
                if (isset($barcodesInFile[$b])) {
                    $add($line, "Barcode {$row['barcode']} bị trùng với dòng {$barcodesInFile[$b]}.");
                } elseif (isset($barcodes[$b]) && $barcodes[$b] !== $lower) {
                    $add($line, "Barcode {$row['barcode']} đã dùng cho SKU khác.");
                }
                $barcodesInFile[$b] = $line;
            }

            foreach (['ref_price', 'opening_cost'] as $money) {
                if ($row[$money] !== '' && (! is_numeric($row[$money]) || (float) $row[$money] < 0)) {
                    $add($line, "{$money} phải là số không âm.");
                }
            }
            foreach (['weight_gram', 'opening_qty'] as $int) {
                if ($row[$int] !== '' && ! ctype_digit($row[$int])) {
                    $add($line, "{$int} phải là số nguyên không âm.");
                }
            }
            if ($row['status'] !== '' && ! in_array($row['status'], Product::STATUSES, true)) {
                $add($line, 'status phải là active, inactive hoặc archived.');
            }
            if ((int) $row['opening_qty'] > 0) {
                if ($match) {
                    $add($line, "SKU {$sku} đã tồn tại: dùng phiếu nhập kho thay vì opening_qty.");
                } elseif (! $openingWarehouse) {
                    $add($line, 'Chọn kho để nhập tồn đầu kỳ.');
                }
            }
        }

        return $errors;
    }

    private function lookup($map, string $name, \Closure $create): ?int
    {
        if ($name === '') {
            return null;
        }
        $key = mb_strtolower($name);

        return $map[$key] ??= $create($name);
    }
}
