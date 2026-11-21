<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Assistant\Support\Similar;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\EInvoiceXml;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * "Hoá đơn mua vào › Tải hoá đơn": XML files (one by one or in a .zip, as the tax portal and suppliers send them)
 * become purchase invoices. The same invoice twice is skipped. Each one is checked: issued to this business (tax
 * code), carries the tax authority's code when its symbol says it must, adds up.
 */
class PurchaseInvoiceService
{
    public const MAX_FILES = 300;

    private const MAX_XML_BYTES = 3_000_000;

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{added: int, duplicates: int, failed: array<int, string>}
     */
    public function import(array $files, ?int $userId): array
    {
        $result = ['added' => 0, 'duplicates' => 0, 'failed' => []];
        $seen = 0;
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            if (strtolower($file->getClientOriginalExtension()) === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open((string) $file->getRealPath()) !== true) {
                    $result['failed'][] = $name;

                    continue;
                }
                for ($i = 0; $i < $zip->numFiles && $seen < self::MAX_FILES; $i++) {
                    $stat = $zip->statIndex($i);
                    $entry = (string) ($stat['name'] ?? '');
                    if (! str_ends_with(strtolower($entry), '.xml') || str_contains($entry, '__MACOSX')) {
                        continue;
                    }
                    $seen++;
                    if ((int) ($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
                        $result['failed'][] = basename($entry);

                        continue;
                    }
                    $this->one((string) $zip->getFromIndex($i), basename($entry), $userId, $result);
                }
                $zip->close();

                continue;
            }
            if (++$seen > self::MAX_FILES) {
                break;
            }
            $this->one((string) file_get_contents((string) $file->getRealPath()), $name, $userId, $result);
        }

        return $result;
    }

    /** @param  array{added: int, duplicates: int, failed: array<int, string>}  $result */
    private function one(string $xml, string $fileName, ?int $userId, array &$result): void
    {
        $doc = EInvoiceXml::parse($xml);
        if ($doc === null) {
            $result['failed'][] = $fileName;

            return;
        }
        $seller = Similar::taxCode((string) $doc['seller']['tax_code']);
        $exists = PurchaseInvoice::query()->where('seller_tax_code', $seller)->where('series', $doc['series'])->where('number', $doc['number'])->exists();
        if ($exists) {
            $result['duplicates']++;

            return;
        }
        PurchaseInvoice::query()->create([
            'kind' => $doc['kind'],
            'series' => mb_substr((string) $doc['series'], 0, 20),
            'number' => mb_substr((string) $doc['number'], 0, 20),
            'issued_on' => $doc['issued_on'],
            'authority_code' => $doc['authority_code'] ? mb_substr((string) $doc['authority_code'], 0, 64) : null,
            'seller_tax_code' => $seller,
            'seller_name' => mb_substr((string) $doc['seller']['name'], 0, 255),
            'seller_address' => mb_substr((string) $doc['seller']['address'], 0, 255) ?: null,
            'seller_phone' => mb_substr((string) $doc['seller']['phone'], 0, 30) ?: null,
            'buyer_tax_code' => Similar::taxCode((string) $doc['buyer']['tax_code']) ?: null,
            'buyer_name' => mb_substr((string) $doc['buyer']['name'], 0, 255) ?: null,
            'currency' => mb_substr((string) $doc['currency'], 0, 3),
            'subtotal' => $doc['subtotal'],
            'discount' => $doc['discount'],
            'shipping' => $doc['shipping'],
            'vat_amount' => $doc['vat_amount'],
            'vat_rate' => $doc['vat_rate'],
            'total' => $doc['total'],
            'lines' => $doc['lines'],
            'issues' => $this->issues($doc),
            'supplier_id' => $this->supplierId($seller),
            'file_name' => mb_substr($fileName, 0, 255),
            'created_by' => $userId,
        ]);
        $result['added']++;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<int, string>
     */
    private function issues(array $doc): array
    {
        $issues = [];
        $own = Similar::taxCode((string) TenantSettings::current()->get('store.tax_code'));
        $buyer = Similar::taxCode((string) $doc['buyer']['tax_code']);
        if ($own !== '' && $buyer !== '' && substr($own, 0, 10) !== substr($buyer, 0, 10)) {
            $issues[] = 'buyer_mismatch';
        } elseif ($own !== '' && $buyer === '') {
            $issues[] = 'no_buyer_tax_code';
        }
        if ($doc['needs_authority_code'] && ! $doc['authority_code']) {
            $issues[] = 'no_authority_code';
        }
        $sum = array_sum(array_column($doc['lines'], 'amount')) + (float) $doc['shipping'] - (float) $doc['discount'];
        if ((float) $doc['total'] > 0 && abs($sum + (float) $doc['vat_amount'] - (float) $doc['total']) > 1000) {
            $issues[] = 'total_mismatch';
        }

        return $issues;
    }

    private function supplierId(string $taxCode): ?int
    {
        if (strlen($taxCode) < 10) {
            return null;
        }

        return Supplier::query()->whereNotNull('tax_code')->get(['id', 'tax_code'])
            ->first(fn (Supplier $s) => substr(Similar::taxCode($s->tax_code), 0, 10) === substr($taxCode, 0, 10))?->id;
    }

    /** "Tạo phiếu nhập": the invoice in the AI reading's shape, for InvoiceReader::draft(). */
    public function reading(PurchaseInvoice $invoice): array
    {
        return [
            'supplier' => ['name' => $invoice->seller_name, 'tax_code' => $invoice->seller_tax_code, 'address' => (string) $invoice->seller_address, 'phone' => (string) $invoice->seller_phone],
            'invoice' => ['number' => $invoice->number, 'series' => $invoice->series, 'date' => $invoice->issued_on?->toDateString() ?? ''],
            'lines' => array_values(array_filter($invoice->lines, fn ($l) => ! ($l['promo'] ?? false) || (float) ($l['quantity'] ?? 0) > 0)),
            'discount' => (float) $invoice->discount,
            'shipping' => (float) $invoice->shipping,
            'vat_rate' => $invoice->vat_rate !== null ? (float) $invoice->vat_rate : null,
            'vat_amount' => (float) $invoice->vat_amount,
            'total' => (float) $invoice->total,
            'unreadable' => '',
        ];
    }

    /** A purchase order made from the invoice: it is now "Đã vào phiếu nhập". */
    public function link(PurchaseInvoice $invoice, PurchaseOrder $po): void
    {
        $invoice->forceFill(['status' => 'linked', 'purchase_order_id' => $po->id, 'supplier_id' => $invoice->supplier_id ?? $po->supplier_id])->save();
    }
}
