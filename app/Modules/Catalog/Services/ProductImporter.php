<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Events\SaleStatusChanged;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ImportHeaders;
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
 *  - Files exported from Sapo or KiotViet are read as they are (see ALIASES): their variants are grouped into one
 *    product by product name, and the attribute values make the variant name.
 *  - With skip_invalid the rows with errors are left out and reported, and the rest is imported.
 */
class ProductImporter
{
    public const COLUMNS = ['product_key', 'product_name', 'sku', 'variant_name', 'barcode', 'category', 'brand', 'ref_price', 'weight_gram', 'status', 'opening_qty', 'opening_cost', 'wholesale_price', 'unit'];

    public const MAX_ROWS = 20000;

    /** key => header patterns (normalized by ImportHeaders), EzSell's own header first, then Sapo's, then KiotViet's. */
    public const ALIASES = [
        'product_key' => ['/^product_key$/', '/^ma_hh_lien_quan$/'],
        'sku' => ['/^sku$/', '/^ma_sku$/', '/^ma_hang$/', '/^ma_hang_hoa$/', '/^ma_san_pham$/'],
        'product_name' => ['/^product_name$/', '/^ten_san_pham$/', '/^ten_hang$/', '/^ten_hang_hoa$/'],
        'variant_name' => ['/^variant_name$/', '/^ten_phien_ban$/'],
        'barcode' => ['/^barcode$/', '/^ma_vach$/'],
        'category' => ['/^category$/', '/^loai_san_pham$/', '/^nhom_hang/', '/^danh_muc$/'],
        'brand' => ['/^brand$/', '/^nhan_hieu$/', '/^thuong_hieu$/'],
        'ref_price' => ['/^ref_price$/', '/^pl_gia_ban_le$/', '/^gia_ban_le$/', '/^gia_ban$/'],
        'wholesale_price' => ['/^wholesale_price$/', '/^pl_gia_ban_buon$/', '/^gia_ban_buon$/', '/^gia_si$/'],
        'weight_gram' => ['/^weight_gram$/', '/^khoi_luong$/', '/^trong_luong$/'],
        'status' => ['/^status$/', '/^dang_kinh_doanh$/'],
        'opening_qty' => ['/^opening_qty$/', '/^lc_.+_ton_kho_ban_dau$/', '/^ton_kho_ban_dau$/', '/^ton_kho$/', '/^ton_dau_ky$/'],
        'opening_cost' => ['/^opening_cost$/', '/^lc_.+_gia_von_khoi_tao$/', '/^gia_von$/', '/^pl_gia_nhap$/', '/^gia_nhap$/'],
        'unit' => ['/^unit$/', '/^don_vi$/', '/^don_vi_tinh$/', '/^dvt$/'],
        // Read to build other fields, not imported as such.
        '_attr1' => ['/^gia_tri_thuoc_tinh_1$/'], '_attr2' => ['/^gia_tri_thuoc_tinh_2$/'], '_attr3' => ['/^gia_tri_thuoc_tinh_3$/'],
        '_attributes' => ['/^thuoc_tinh$/'],
        '_weight_unit' => ['/^don_vi_khoi_luong$/'],
    ];

    public function __construct(private readonly InventoryDocumentService $documents, private readonly AuditLogger $audit) {}

    /**
     * @return array{rows: array<int, array<string, string>>, errors: array<int, array{row: int, message: string}>, source: string, columns: list<array{header: string, key: string|null}>}
     */
    public function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);

        $rows = [];
        $errors = [];
        $header = null;
        $source = ImportHeaders::EZSELL;
        $columns = [];
        $line = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $row->toArray());

                if ($header === null) {
                    $source = ImportHeaders::source($cells);
                    $header = ImportHeaders::map($cells, self::ALIASES);
                    foreach ($cells as $index => $title) {
                        if (trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $title)) !== '') {
                            $key = array_search($index, $header, true);
                            $key = $key === false ? null : ($key === '_weight_unit' ? 'weight_gram' : (str_starts_with($key, '_') ? 'variant_name' : $key));
                            $columns[] = ['header' => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $title)), 'key' => $key];
                        }
                    }
                    foreach (['product_name', 'sku'] as $required) {
                        if (! isset($header[$required])) {
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
                    $assoc[$column] = isset($header[$column]) ? ($cells[$header[$column]] ?? '') : '';
                }
                $rows[$line] = $source === ImportHeaders::EZSELL ? $assoc : $this->fromOtherApp($assoc, $cells, $header, $source);
            }
            break; // first sheet only
        }

        $reader->close();

        return ['rows' => $rows, 'errors' => $errors, 'source' => $source, 'columns' => $columns];
    }

    /**
     * A row of a Sapo or KiotViet file in EzSell's terms: numbers without thousand separators, the attribute
     * values as the variant name, variants of one product grouped by its name, weight in grams.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $cells
     * @param  array<string, int>  $header
     * @return array<string, string>
     */
    private function fromOtherApp(array $row, array $cells, array $header, string $source): array
    {
        $cell = fn (string $key) => isset($header[$key]) ? trim((string) ($cells[$header[$key]] ?? '')) : '';

        if ($row['variant_name'] === '') {
            $values = array_filter([$cell('_attr1'), $cell('_attr2'), $cell('_attr3')], fn ($v) => $v !== '');
            if ($values === [] && $cell('_attributes') !== '') {
                // KiotViet: "MAUSAC:Đỏ|SIZE:M"
                foreach (preg_split('/\|/', $cell('_attributes')) ?: [] as $pair) {
                    $value = trim(str_contains($pair, ':') ? substr($pair, strpos($pair, ':') + 1) : $pair);
                    if ($value !== '') {
                        $values[] = $value;
                    }
                }
            }
            $row['variant_name'] = implode(' / ', $values);
        }
        if ($row['product_key'] === '' && $row['product_name'] !== '') {
            $row['product_key'] = 'name:'.mb_strtolower($row['product_name']);
        }
        if ($source === ImportHeaders::KIOTVIET && $row['variant_name'] !== '' && $row['product_name'] !== '') {
            // KiotViet names each variant "Áo thun - Đỏ - M": the product is the part before the attributes.
            $suffix = ' - '.str_replace(' / ', ' - ', $row['variant_name']);
            if (str_ends_with($row['product_name'], $suffix)) {
                $row['product_name'] = mb_substr($row['product_name'], 0, mb_strlen($row['product_name']) - mb_strlen($suffix));
                $row['product_key'] = 'name:'.mb_strtolower($row['product_name']);
            }
        }
        if (str_contains($row['category'], '>>')) {
            $parts = array_filter(array_map('trim', explode('>>', $row['category'])));
            $row['category'] = (string) end($parts);
        }
        foreach (['ref_price', 'wholesale_price', 'opening_cost'] as $money) {
            $row[$money] = ImportHeaders::number($row[$money]);
        }
        $row['opening_qty'] = ImportHeaders::integer($row['opening_qty']);
        if (is_numeric($row['opening_qty']) && (int) $row['opening_qty'] < 0) {
            $row['opening_qty'] = ''; // KiotViet lets stock go below zero: nothing to bring over.
        }
        if ($row['weight_gram'] !== '') {
            $weight = ImportHeaders::number($row['weight_gram']);
            if (is_numeric($weight)) {
                $row['weight_gram'] = (string) (int) round((float) $weight * (mb_strtolower($cell('_weight_unit')) === 'kg' ? 1000 : 1));
            }
        }
        if ($row['status'] !== '') {
            $row['status'] = match (mb_strtolower($row['status'])) {
                '1', 'true', 'có', 'co', 'x', 'đang kinh doanh', 'active' => Product::STATUS_ACTIVE,
                '0', 'false', 'không', 'khong', 'ngừng kinh doanh', 'inactive' => 'inactive',
                default => $row['status'],
            };
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, string>>  $rows  keyed by file line number
     * @return array{created: int, updated: int, products: int, errors: array<int, array{row: int, message: string}>, opening_receipt: string|null, dry_run: bool, zero_cost_rows: array<int, int>, skipped: list<array{row: int, message: string}>}
     */
    public function import(array $rows, bool $dryRun = false, ?Warehouse $openingWarehouse = null, bool $skipInvalid = false): array
    {
        $existing = ProductVariant::query()->withTrashed()->get(['id', 'sku', 'barcode', 'product_id', 'deleted_at'])
            ->keyBy(fn ($v) => mb_strtolower($v->sku));
        $barcodes = $existing->filter(fn ($v) => filled($v->barcode))->mapWithKeys(fn ($v) => [mb_strtolower($v->barcode) => mb_strtolower($v->sku)]);

        $errors = $this->validate($rows, $existing, $barcodes, $openingWarehouse);
        // Leaving rows out can change what is left (a duplicate SKU no longer duplicates): checked again until clean.
        $skipped = [];
        while ($skipInvalid && $errors !== []) {
            foreach ($errors as $error) {
                unset($rows[$error['row']]);
                $skipped[] = $error;
            }
            $errors = $this->validate($rows, $existing, $barcodes, $openingWarehouse);
        }
        $creates = 0;
        $updates = 0;
        foreach ($rows as $row) {
            isset($existing[mb_strtolower($row['sku'])]) ? $updates++ : $creates++;
        }

        // Opening stock without a cost starts the average at 0 ₫ (gross profit too high): not an error, but reported.
        $zeroCost = [];
        foreach ($rows as $line => $row) {
            if ((int) $row['opening_qty'] > 0 && ! ((float) $row['opening_cost'] > 0) && ! isset($existing[mb_strtolower($row['sku'])])) {
                $zeroCost[] = (int) $line;
            }
        }

        if ($errors || $dryRun) {
            return ['created' => $errors ? 0 : $creates, 'updated' => $errors ? 0 : $updates, 'products' => 0, 'errors' => $errors, 'opening_receipt' => null, 'dry_run' => $dryRun, 'zero_cost_rows' => $zeroCost, 'skipped' => $skipped];
        }

        return DB::transaction(function () use ($rows, $existing, $openingWarehouse, $creates, $updates, $zeroCost, $skipped) {
            $categories = Category::query()->whereNull('parent_id')->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
            $brands = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
            $productsByKey = [];
            $productCount = 0;
            $opening = [];
            $statusChanged = [];

            foreach ($rows as $row) {
                $categoryId = $this->lookup($categories, $row['category'], fn ($name) => Category::create(['name' => $name])->id);
                $brandId = $this->lookup($brands, $row['brand'], fn ($name) => Brand::create(['name' => $name])->id);
                $variantData = array_filter([
                    'name' => $row['variant_name'] ?: null,
                    'barcode' => $row['barcode'] ?: null,
                    'ref_price' => $row['ref_price'] !== '' ? $row['ref_price'] : null,
                    'wholesale_price' => $row['wholesale_price'] !== '' ? $row['wholesale_price'] : null,
                    'weight_gram' => $row['weight_gram'] !== '' ? (int) $row['weight_gram'] : null,
                    'status' => $row['status'] ?: null,
                ], fn ($v) => $v !== null);

                $match = $existing[mb_strtolower($row['sku'])] ?? null;
                if ($match) {
                    // Saved through the models so a new price reaches the websites and the price history, like an edit.
                    $variant = ProductVariant::query()->withTrashed()->findOrFail($match->id);
                    $variant->fill($variantData)->save();
                    if ($variant->wasChanged('status')) {
                        $statusChanged[] = $variant->id;
                    }
                    Product::query()->withTrashed()->findOrFail($match->product_id)->fill(array_filter([
                        'name' => $row['product_name'], 'category_id' => $categoryId, 'brand_id' => $brandId, 'unit' => $row['unit'] ?: null,
                    ]))->save();

                    continue;
                }

                $key = $row['product_key'] !== '' ? mb_strtolower($row['product_key']) : null;
                $product = $key !== null ? ($productsByKey[$key] ?? null) : null;
                if ($product === null) {
                    $product = Product::create(['name' => $row['product_name'], 'category_id' => $categoryId, 'brand_id' => $brandId, 'unit' => $row['unit'] ?: null, 'status' => Product::STATUS_ACTIVE]);
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

            if ($statusChanged !== []) {
                $tenantId = app(TenantContext::class)->id();
                DB::afterCommit(fn () => SaleStatusChanged::dispatch($tenantId, $statusChanged));
            }

            $this->audit->record('product.imported', null, ['created' => $creates, 'updated' => $updates, 'products' => $productCount, 'skipped' => count($skipped)]);

            return ['created' => $creates, 'updated' => $updates, 'products' => $productCount, 'errors' => [], 'opening_receipt' => $receipt?->number, 'dry_run' => false, 'zero_cost_rows' => $zeroCost, 'skipped' => $skipped];
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
            if (mb_strlen($row['unit']) > 30) {
                $add($line, 'Đơn vị tính tối đa 30 ký tự.');
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

            foreach (['ref_price', 'wholesale_price', 'opening_cost'] as $money) {
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
