<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExporter
{
    public const COLUMNS = ['product_key', 'product_name', 'sku', 'variant_name', 'barcode', 'category', 'brand', 'ref_price', 'weight_gram', 'status', 'cost_price', 'on_hand', 'reserved', 'available'];

    public function stream(string $format): StreamedResponse
    {
        $format = $format === 'xlsx' ? 'xlsx' : 'csv';
        $filename = 'san-pham-'.now()->format('Ymd-His').'.'.$format;

        // The body is streamed after the middleware stack (and its tenant context) has finished, so the
        // tenant is captured now and re-entered inside the callback.
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();

        return response()->streamDownload(fn () => TenantContext::run($tenant, fn () => $this->write($format), $membership), $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ]);
    }

    private function write(string $format): void
    {
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile('php://output');
        $writer->addRow(Row::fromValues(self::COLUMNS));

        $stock = DB::table('stock_balances')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->selectRaw('product_variant_id, SUM(on_hand) as on_hand, SUM(reserved) as reserved')
            ->groupBy('product_variant_id');

        ProductVariant::query()
            ->with(['product.category', 'product.brand'])
            ->leftJoinSub($stock, 's', 's.product_variant_id', '=', 'product_variants.id')
            ->whereHas('product')
            ->select('product_variants.*', 's.on_hand', 's.reserved')
            ->orderBy('product_variants.product_id')->orderBy('product_variants.id')
            ->chunk(1000, function ($variants) use ($writer) {
                foreach ($variants as $v) {
                    $writer->addRow(Row::fromValues([
                        $v->product->ulid, $v->product->name, $v->sku, (string) $v->name, (string) $v->barcode,
                        (string) $v->product->category?->name, (string) $v->product->brand?->name,
                        (string) $v->ref_price, (string) $v->weight_gram, $v->status, (string) $v->cost_price,
                        (int) $v->on_hand, (int) $v->reserved, (int) $v->on_hand - (int) $v->reserved,
                    ]));
                }
            });

        $writer->close();
    }
}
