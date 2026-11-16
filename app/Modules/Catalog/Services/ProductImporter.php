<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Catalog\Events\SaleStatusChanged;
use App\Modules\Catalog\Jobs\DownloadProductImagesJob;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
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
 *  - A Sapo file has stock and cost per branch ("LC_<branch>_Tồn kho ban đầu"): each branch goes to the warehouse
 *    chosen for it, one opening receipt per warehouse. SKUs already in EzSell never get stock from a file of another app.
 *  - Descriptions (HTML made plain), tags and picture links come along; pictures are downloaded afterwards
 *    (DownloadProductImagesJob). Rows read by SapoGoReader may also carry combo and pack components.
 */
class ProductImporter
{
    public const COLUMNS = ['product_key', 'product_name', 'sku', 'variant_name', 'barcode', 'category', 'brand', 'ref_price', 'weight_gram', 'status', 'opening_qty', 'opening_cost', 'wholesale_price', 'unit', 'description', 'tags', 'image_urls'];

    /** Images kept per product, as on the product form. */
    public const MAX_IMAGES = 9;

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
        'opening_qty' => ['/^opening_qty$/', '/^ton_kho_ban_dau$/', '/^ton_kho$/', '/^ton_dau_ky$/'],
        'opening_cost' => ['/^opening_cost$/', '/^gia_von$/', '/^pl_gia_nhap$/', '/^gia_nhap$/'],
        'unit' => ['/^unit$/', '/^don_vi$/', '/^don_vi_tinh$/', '/^dvt$/'],
        'description' => ['/^description$/', '/^mo_ta_san_pham$/', '/^mo_ta$/'],
        'tags' => ['/^tags?$/'],
        'image_urls' => ['/^image_urls?$/', '/^anh_dai_dien$/', '/^link_hinh_anh$/', '/^anh_san_pham$/', '/^hinh_anh/'],
        // Read to build other fields, not imported as such.
        '_attr1' => ['/^gia_tri_thuoc_tinh_1$/'], '_attr2' => ['/^gia_tri_thuoc_tinh_2$/'], '_attr3' => ['/^gia_tri_thuoc_tinh_3$/'],
        '_attributes' => ['/^thuoc_tinh$/'],
        '_weight_unit' => ['/^don_vi_khoi_luong$/'],
    ];

    public function __construct(private readonly InventoryDocumentService $documents, private readonly AuditLogger $audit) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, array{row: int, message: string}>, source: string, columns: list<array{header: string, key: string|null}>, branches: array<string, int>}
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
        $branchColumns = [];
        $line = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $row->toArray());

                if ($header === null) {
                    $source = ImportHeaders::source($cells);
                    $header = ImportHeaders::map($cells, self::ALIASES);
                    $branchColumns = self::branchColumns($cells);
                    foreach ($cells as $index => $title) {
                        if (trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $title)) !== '') {
                            $key = array_search($index, $header, true);
                            $key = $key === false ? null : ($key === '_weight_unit' ? 'weight_gram' : (str_starts_with($key, '_') ? 'variant_name' : $key));
                            foreach ($branchColumns as $branch) {
                                $key = $branch['qty'] === $index ? 'branch_qty' : ($branch['cost'] === $index ? 'branch_cost' : $key);
                            }
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
                $row = $source === ImportHeaders::EZSELL ? $assoc : $this->fromOtherApp($assoc, $cells, $header, $source) + ['_from' => $source];
                if ($branchColumns !== []) {
                    $row['_stock'] = [];
                    foreach ($branchColumns as $name => $branch) {
                        $row['_stock'][$name] = [
                            'qty' => $branch['qty'] === null ? '' : ImportHeaders::integer(trim((string) ($cells[$branch['qty']] ?? ''))),
                            'cost' => $branch['cost'] === null ? '' : ImportHeaders::number(trim((string) ($cells[$branch['cost']] ?? ''))),
                        ];
                    }
                }
                $rows[$line] = $row;
            }
            break; // first sheet only
        }

        $reader->close();

        return ['rows' => $rows, 'errors' => $errors, 'source' => $source, 'columns' => $columns, 'branches' => self::branchTotals($rows)];
    }

    /**
     * Sapo writes one pair of columns per branch: "LC_Cửa hàng chính_Tồn kho ban đầu" and "LC_Cửa hàng chính_Giá vốn
     * khởi tạo". Each branch keeps its own stock and cost, so each can go to its own warehouse.
     *
     * @param  list<string>  $cells
     * @return array<string, array{qty: int|null, cost: int|null}> branch name => column indexes
     */
    private static function branchColumns(array $cells): array
    {
        $branches = [];
        foreach ($cells as $index => $title) {
            if (! preg_match('/^lc_.+_(ton_kho_ban_dau|gia_von_khoi_tao)$/', ImportHeaders::normalize((string) $title), $m)) {
                continue;
            }
            $title = rtrim(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $title)), '* ');
            $name = trim(mb_substr($title, 3, mb_strrpos($title, '_') - 3), " _\t");
            if ($name === '') {
                continue;
            }
            $branches[$name] ??= ['qty' => null, 'cost' => null];
            $branches[$name][$m[1] === 'ton_kho_ban_dau' ? 'qty' : 'cost'] = $index;
        }

        return $branches;
    }

    /**
     * Stock of each branch in the file (negative stock counts as none).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function branchTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            foreach ($row['_stock'] ?? [] as $name => $stock) {
                $totals[$name] = ($totals[$name] ?? 0) + (ctype_digit((string) $stock['qty']) ? (int) $stock['qty'] : 0);
            }
        }

        return $totals;
    }

    /**
     * Which warehouse receives each branch's stock. A branch the user chose for is kept as chosen (empty: not brought
     * over); otherwise a selling warehouse of the same name, or with a single branch the warehouse picked for opening stock.
     *
     * @param  list<string>  $branches
     * @param  array<string, string|null>  $chosen  branch name => warehouse ulid, '' or null for none
     * @return array<string, Warehouse|null>
     */
    public function branchWarehouses(array $branches, array $chosen, ?Warehouse $default): array
    {
        $warehouses = Warehouse::query()->get()->filter(fn (Warehouse $w) => $w->sells());
        $byName = $warehouses->keyBy(fn (Warehouse $w) => mb_strtolower(trim($w->name)));
        $byUlid = $warehouses->keyBy('ulid');
        $map = [];
        foreach ($branches as $name) {
            if (array_key_exists($name, $chosen)) {
                $map[$name] = filled($chosen[$name]) ? ($byUlid[$chosen[$name]] ?? throw new \InvalidArgumentException("Kho cho chi nhánh {$name} không hợp lệ.")) : null;
            } else {
                $map[$name] = $byName[mb_strtolower(trim($name))] ?? (count($branches) === 1 ? $default : null);
            }
        }

        return $map;
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
     * @param  array<int, array<string, mixed>>  $rows  keyed by file line number
     * @param  array<string, Warehouse|null>  $branchWarehouses  branch name => warehouse receiving its stock (none: not brought over)
     * @return array{created: int, updated: int, products: int, errors: array<int, array{row: int, message: string}>, opening_receipt: string|null, opening_receipts: list<string>, dry_run: bool, zero_cost_rows: array<int, int>, skipped: list<array{row: int, message: string}>, warnings: list<array{row: int, message: string}>, images: int}
     */
    public function import(array $rows, bool $dryRun = false, ?Warehouse $openingWarehouse = null, bool $skipInvalid = false, array $branchWarehouses = [], bool $withImages = true): array
    {
        $existing = ProductVariant::query()->withTrashed()->get(['id', 'sku', 'barcode', 'product_id', 'deleted_at'])
            ->keyBy(fn ($v) => mb_strtolower($v->sku));
        $barcodes = $existing->filter(fn ($v) => filled($v->barcode))->mapWithKeys(fn ($v) => [mb_strtolower($v->barcode) => mb_strtolower($v->sku)]);

        [$rows, $tidied] = $this->tidy($rows, $barcodes);
        [$errors, $warnings] = $this->validate($rows, $existing, $barcodes, $openingWarehouse, $branchWarehouses);
        // Leaving rows out can change what is left (a duplicate SKU no longer duplicates): checked again until clean.
        $skipped = [];
        while ($skipInvalid && $errors !== []) {
            foreach ($errors as $error) {
                unset($rows[$error['row']]);
                $skipped[] = $error;
            }
            [$errors, $warnings] = $this->validate($rows, $existing, $barcodes, $openingWarehouse, $branchWarehouses);
        }
        $warnings = array_merge(array_values(array_filter($tidied, fn ($w) => isset($rows[$w['row']]))), $warnings);
        $creates = 0;
        $updates = 0;
        foreach ($rows as $row) {
            isset($existing[mb_strtolower($row['sku'])]) ? $updates++ : $creates++;
        }

        // Opening stock without a cost starts the average at 0 ₫ (gross profit too high): not an error, but reported.
        $zeroCost = [];
        foreach ($rows as $line => $row) {
            if (isset($existing[mb_strtolower($row['sku'])])) {
                continue;
            }
            foreach ($this->openingLines($row, $openingWarehouse, $branchWarehouses) as $opening) {
                if (! ((float) $opening['unit_cost'] > 0)) {
                    $zeroCost[] = (int) $line;
                    break;
                }
            }
        }
        $imageCount = $withImages ? array_sum(array_map(fn ($row) => count(self::imageUrls((string) ($row['image_urls'] ?? ''))), $rows)) : 0;

        if ($errors || $dryRun) {
            return ['created' => $errors ? 0 : $creates, 'updated' => $errors ? 0 : $updates, 'products' => 0, 'errors' => $errors, 'opening_receipt' => null, 'opening_receipts' => [], 'dry_run' => $dryRun, 'zero_cost_rows' => $zeroCost, 'skipped' => $skipped, 'warnings' => $warnings, 'images' => $imageCount];
        }

        $result = DB::transaction(function () use ($rows, $existing, $openingWarehouse, $branchWarehouses, $creates, $updates, $zeroCost, $skipped, $warnings, $withImages) {
            $categories = Category::query()->whereNull('parent_id')->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
            $brands = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
            $productsByKey = [];
            $productCount = 0;
            $opening = [];
            $statusChanged = [];
            $images = [];
            $combos = [];
            $created = [];

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
                $productData = array_filter([
                    'name' => $row['product_name'], 'category_id' => $categoryId, 'brand_id' => $brandId, 'unit' => $row['unit'] ?: null,
                    'description' => self::plainText((string) ($row['description'] ?? '')) ?: null, 'tags' => self::tags((string) ($row['tags'] ?? '')) ?: null,
                ]);
                $urls = $withImages ? self::imageUrls((string) ($row['image_urls'] ?? '')) : [];

                $match = $existing[mb_strtolower($row['sku'])] ?? null;
                if ($match) {
                    // Saved through the models so a new price reaches the websites and the price history, like an edit.
                    $variant = ProductVariant::query()->withTrashed()->findOrFail($match->id);
                    $variant->fill($variantData)->save();
                    if ($variant->wasChanged('status')) {
                        $statusChanged[] = $variant->id;
                    }
                    Product::query()->withTrashed()->findOrFail($match->product_id)->fill($productData)->save();
                    if ($urls !== []) {
                        $images[$match->product_id] = array_merge($images[$match->product_id] ?? [], $urls);
                    }

                    continue;
                }

                $key = $row['product_key'] !== '' ? mb_strtolower($row['product_key']) : null;
                $product = $key !== null ? ($productsByKey[$key] ?? null) : null;
                if ($product === null) {
                    $product = Product::create($productData + ['status' => Product::STATUS_ACTIVE]);
                    if (($row['_type'] ?? '') === Product::TYPE_COMBO) {
                        $product->forceFill(['type' => Product::TYPE_COMBO])->save();
                    }
                    $productCount++;
                    if ($key !== null) {
                        $productsByKey[$key] = $product;
                    }
                }
                if ($urls !== []) {
                    $images[$product->id] = array_merge($images[$product->id] ?? [], $urls);
                }

                $variant = $product->variants()->create(['sku' => ProductVariant::normalizeSku($row['sku'])] + $variantData);
                $created[mb_strtolower($variant->sku)] = $variant->id;
                if (! empty($row['_components'])) {
                    $combos[] = [$variant, $row['_components']];

                    continue; // a combo or a pack holds its stock on its components
                }

                foreach ($this->openingLines($row, $openingWarehouse, $branchWarehouses) as $line) {
                    $opening[$line['warehouse']->id] ??= ['warehouse' => $line['warehouse'], 'lines' => []];
                    $opening[$line['warehouse']->id]['lines'][] = ['variant_id' => $variant->id, 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost']];
                }
            }

            foreach ($combos as [$combo, $components]) {
                foreach ($components as $component) {
                    $sku = mb_strtolower(trim((string) $component['sku']));
                    $combo->components()->create(['component_variant_id' => $created[$sku] ?? $existing[$sku]->id, 'quantity' => (int) $component['quantity']]);
                }
            }

            $receipts = [];
            foreach ($opening as $group) {
                $receipts[] = $this->documents->receive($group['warehouse'], $group['lines'], 'Tồn đầu kỳ từ import sản phẩm', StockReceipt::SOURCE_OPENING)->number;
            }

            if ($statusChanged !== []) {
                $tenantId = app(TenantContext::class)->id();
                DB::afterCommit(fn () => SaleStatusChanged::dispatch($tenantId, $statusChanged));
            }

            // Pictures are downloaded after the import: only for products that have none yet, so a second import adds none.
            $withPictures = $images === [] ? [] : ProductImage::query()->whereIn('product_id', array_keys($images))->distinct()->pluck('product_id')->all();
            $images = array_map(fn ($urls) => array_slice(array_values(array_unique($urls)), 0, self::MAX_IMAGES), array_diff_key($images, array_flip($withPictures)));
            if ($images !== []) {
                $tenantId = app(TenantContext::class)->id();
                DB::afterCommit(fn () => DownloadProductImagesJob::dispatch($tenantId, $images));
            }

            $this->audit->record('product.imported', null, ['created' => $creates, 'updated' => $updates, 'products' => $productCount, 'skipped' => count($skipped), 'images' => count($images)]);

            return ['created' => $creates, 'updated' => $updates, 'products' => $productCount, 'errors' => [], 'opening_receipt' => $receipts[0] ?? null, 'opening_receipts' => $receipts, 'dry_run' => false, 'zero_cost_rows' => $zeroCost, 'skipped' => $skipped, 'warnings' => $warnings, 'images' => array_sum(array_map('count', $images))];
        });

        return $result;
    }

    /**
     * Files from other apps carry what EzSell refuses: SKUs with spaces or brackets ("LCD PL241V8") and one barcode on
     * several SKUs. Rather than leave those products behind, the SKU is written with dashes and the barcode kept on the
     * first SKU only; each change is reported. EzSell's own file is left as it is.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: list<array{row: int, message: string}>}
     */
    private function tidy(array $rows, $barcodes): array
    {
        $notes = [];
        $seen = [];
        foreach ($rows as $line => $row) {
            if (empty($row['_from'])) {
                continue;
            }
            $sku = (string) $row['sku'];
            $fixed = self::tidySku($sku);
            if ($fixed !== $sku) {
                $rows[$line]['sku'] = $fixed;
                $notes[] = ['row' => (int) $line, 'message' => "SKU \"{$sku}\" có ký tự không dùng được, đổi thành {$fixed}."];
            }
            foreach ($row['_components'] ?? [] as $i => $component) {
                $rows[$line]['_components'][$i]['sku'] = self::tidySku((string) $component['sku']);
            }
            $barcode = mb_strtolower((string) $row['barcode']);
            if ($barcode === '') {
                continue;
            }
            $owner = $barcodes[$barcode] ?? null;
            if (isset($seen[$barcode]) || ($owner !== null && $owner !== mb_strtolower((string) $rows[$line]['sku']))) {
                $rows[$line]['barcode'] = '';
                $notes[] = ['row' => (int) $line, 'message' => "Mã vạch {$row['barcode']} đã dùng cho SKU khác, SKU {$rows[$line]['sku']} được nhập không có mã vạch."];
            } else {
                $seen[$barcode] = true;
            }
        }

        return [$rows, $notes];
    }

    /** "LCD PL241V8" → "LCD-PL241V8"; a SKU that cannot be mended is returned as it is, for the check to report. */
    private static function tidySku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '' || preg_match('/^[\pL\pN._\-\/]+$/u', $sku)) {
            return $sku;
        }
        $fixed = trim((string) preg_replace(['/[^\pL\pN._\-\/]+/u', '/-{2,}/'], ['-', '-'], $sku), '-');

        return $fixed !== '' && mb_strlen($fixed) <= 64 ? $fixed : $sku;
    }

    /**
     * Opening stock of one row: per branch into the warehouse chosen for it, or the single opening_qty column into the
     * opening warehouse. A branch without its own cost uses the row's cost (Sapo "Giá nhập").
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, Warehouse|null>  $branchWarehouses
     * @return list<array{warehouse: Warehouse, quantity: int, unit_cost: string}>
     */
    private function openingLines(array $row, ?Warehouse $openingWarehouse, array $branchWarehouses): array
    {
        $lines = [];
        if (isset($row['_stock'])) {
            foreach ($row['_stock'] as $branch => $stock) {
                $warehouse = $branchWarehouses[$branch] ?? null;
                if ($warehouse && ctype_digit((string) $stock['qty']) && (int) $stock['qty'] > 0) {
                    $cost = is_numeric($stock['cost']) && (float) $stock['cost'] > 0 ? (string) $stock['cost'] : ($row['opening_cost'] !== '' ? (string) $row['opening_cost'] : '0');
                    $lines[] = ['warehouse' => $warehouse, 'quantity' => (int) $stock['qty'], 'unit_cost' => $cost];
                }
            }

            return $lines;
        }
        if ($openingWarehouse && (int) $row['opening_qty'] > 0) {
            $lines[] = ['warehouse' => $openingWarehouse, 'quantity' => (int) $row['opening_qty'], 'unit_cost' => $row['opening_cost'] !== '' ? (string) $row['opening_cost'] : '0'];
        }

        return $lines;
    }

    /** Sapo keeps descriptions as HTML; the product form holds plain text. */
    public static function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h[1-6]|tr)>#i'], "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["/[ \t\u{00A0}]+/u", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $text) ?? $text;

        return mb_substr(trim($text), 0, 20000);
    }

    /** @return list<string> "a, b; c" → up to 20 tags of at most 40 characters. */
    public static function tags(string $value): array
    {
        $tags = [];
        foreach (preg_split('/[,;|\n]/', $value) ?: [] as $tag) {
            $tag = mb_substr(trim($tag), 0, 40);
            if ($tag !== '' && ! isset($tags[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }

        return array_slice(array_values($tags), 0, 20);
    }

    /** @return list<string> the web addresses of pictures in a cell (one or several, separated by spaces, commas or new lines) */
    public static function imageUrls(string $value): array
    {
        $urls = [];
        foreach (preg_split('/[\s,;|]+/', trim($value)) ?: [] as $url) {
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }
            if (preg_match('#^https?://[^\s/]+/\S+$#i', $url) && mb_strlen($url) <= 1000) {
                $urls[] = $url;
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_IMAGES);
    }

    /**
     * @param  array<string, Warehouse|null>  $branchWarehouses
     * @return array{0: array<int, array{row: int, message: string}>, 1: list<array{row: int, message: string}>} errors, warnings
     */
    private function validate(array $rows, $existing, $barcodes, ?Warehouse $openingWarehouse, array $branchWarehouses): array
    {
        $errors = [];
        $warnings = [];
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

            if (isset($row['_stock'])) {
                // A file from another app: stock that is not a whole number (or negative) is not brought over.
                $hasStock = false;
                foreach ($row['_stock'] as $branch => $stock) {
                    if ($stock['cost'] !== '' && (! is_numeric($stock['cost']) || (float) $stock['cost'] < 0)) {
                        $add($line, "Giá vốn chi nhánh {$branch} phải là số không âm.");
                    }
                    $hasStock = $hasStock || (($branchWarehouses[$branch] ?? null) && ctype_digit((string) $stock['qty']) && (int) $stock['qty'] > 0);
                }
                if ($hasStock && $match) {
                    $warnings[] = ['row' => $line, 'message' => "SKU {$sku} đã có trong EzSell: chỉ cập nhật thông tin, không cộng tồn kho."];
                }
            } elseif ((int) $row['opening_qty'] > 0) {
                if ($match) {
                    $add($line, "SKU {$sku} đã tồn tại: dùng phiếu nhập kho thay vì opening_qty.");
                } elseif (! $openingWarehouse) {
                    $add($line, 'Chọn kho để nhập tồn đầu kỳ.');
                }
            }
        }

        // Combo and pack components are found by SKU, in the file or among the SKUs already in EzSell.
        foreach ($rows as $line => $row) {
            foreach ($row['_components'] ?? [] as $component) {
                $sku = mb_strtolower(trim((string) $component['sku']));
                $found = isset($skusInFile[$sku]) && empty($rows[$skusInFile[$sku]]['_components']) || (isset($existing[$sku]) && ! $existing[$sku]->deleted_at);
                if (! $found || $sku === mb_strtolower($row['sku'])) {
                    $add($line, "Thành phần {$component['sku']} của {$row['sku']} không có trong file hoặc EzSell.");
                } elseif ((int) $component['quantity'] < 1) {
                    $add($line, "Số lượng thành phần {$component['sku']} phải lớn hơn 0.");
                }
            }
        }

        return [$errors, $warnings];
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
