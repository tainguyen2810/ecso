<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Support\Similar;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * "Chụp hoá đơn nhập hàng": turns what AI read on a supplier invoice into a purchase-order draft. Each line is
 * matched with a SKU: same code (SKU, barcode or the supplier's code) first, then the closest name. The page
 * shows "Khớp" (sure), "Gần giống" (pick or confirm) or "Hàng mới".
 */
class InvoiceReader
{
    public const SURE = 0.75;

    public const MAYBE = 0.4;

    /**
     * @param  array<string, mixed>  $read  the AI answer
     * @return array<string, mixed>
     */
    public function draft(array $read): array
    {
        $supplierRead = (array) ($read['supplier'] ?? []);
        $supplier = $this->supplier((string) ($supplierRead['name'] ?? ''), (string) ($supplierRead['tax_code'] ?? ''));
        $variants = $this->catalogue();
        $lines = [];
        foreach (array_slice((array) ($read['lines'] ?? []), 0, 200) as $raw) {
            $l = (array) $raw;
            $name = trim((string) ($l['name'] ?? ''));
            $qty = $this->num($l['quantity'] ?? 0);
            $price = $this->num($l['unit_price'] ?? 0);
            $amount = $this->num($l['amount'] ?? 0);
            if ($name === '' && $qty <= 0) {
                continue;
            }
            if ($price <= 0 && $qty > 0 && $amount > 0) {
                $price = round($amount / $qty);
            }
            $lines[] = ['name' => $name, 'code' => trim((string) ($l['code'] ?? '')), 'unit' => trim((string) ($l['unit'] ?? '')),
                'quantity' => $qty, 'unit_price' => $price, 'amount' => $amount > 0 ? $amount : round($qty * $price)]
                + $this->match($name, (string) ($l['code'] ?? ''), $variants, $supplier?->id);
        }
        $discount = $this->num($read['discount'] ?? 0);
        $shipping = $this->num($read['shipping'] ?? 0);
        $vat = $this->num($read['vat_amount'] ?? 0);
        $total = $this->num($read['total'] ?? 0);
        $sum = array_sum(array_column($lines, 'amount'));
        // The printed total may or may not include VAT: either way counts as matching (±1.000 đ for rounding).
        $computed = $sum - $discount + $shipping;
        $matches = $total <= 0 ? null : (abs($computed - $total) <= 1000 || abs($computed + $vat - $total) <= 1000);
        $invoice = (array) ($read['invoice'] ?? []);
        $date = (string) ($invoice['date'] ?? '');

        return [
            'supplier' => [
                'read' => ['name' => (string) ($supplierRead['name'] ?? ''), 'tax_code' => Similar::taxCode((string) ($supplierRead['tax_code'] ?? '')), 'address' => (string) ($supplierRead['address'] ?? ''), 'phone' => (string) ($supplierRead['phone'] ?? '')],
                'match' => $supplier ? ['id' => $supplier->ulid, 'name' => $supplier->name] : null,
            ],
            'invoice' => [
                'number' => trim((string) ($invoice['number'] ?? '')),
                'series' => trim((string) ($invoice['series'] ?? '')),
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
            ],
            'lines' => $lines,
            'discount' => $discount,
            'shipping' => $shipping,
            'vat_amount' => $vat,
            'vat_rate' => is_numeric($read['vat_rate'] ?? null) ? (float) $read['vat_rate'] : null,
            'total' => $total,
            'sum' => $sum,
            'total_matches' => $matches,
            'unreadable' => trim((string) ($read['unreadable'] ?? '')),
        ];
    }

    private function num(mixed $v): float
    {
        if (is_string($v) && preg_match('/^\s*\d{1,3}([.,]\d{3})+\s*(đ|vnd)?\s*$/iu', $v) === 1) {
            return (float) preg_replace('/\D/', '', $v);
        }
        if (is_numeric($v)) {
            return max(0, (float) $v);
        }
        // "1.250.000" / "1,250,000đ": dots and commas group thousands.
        $digits = preg_replace('/[^\d]/', '', (string) $v);

        return $digits === '' ? 0.0 : (float) $digits;
    }

    private function supplier(string $name, string $taxCode): ?Supplier
    {
        $tax = Similar::taxCode($taxCode);
        $all = Supplier::query()->get(['id', 'ulid', 'name', 'tax_code']);
        if (strlen($tax) >= 10) {
            $hit = $all->first(fn ($s) => Similar::taxCode($s->tax_code) !== '' && str_starts_with($tax, substr(Similar::taxCode($s->tax_code), 0, 10)));
            if ($hit) {
                return $hit;
            }
        }
        if (trim($name) === '') {
            return null;
        }
        $best = $all->map(fn ($s) => [$s, Similar::company($name, $s->name)])->sortByDesc(fn ($x) => $x[1])->first();

        return $best && $best[1] >= 0.75 ? $best[0] : null;
    }

    /** @return Collection<int, array{v: ProductVariant, label: string, codes: array<int, string>}> */
    private function catalogue(): Collection
    {
        return ProductVariant::query()->with('product:id,name')->where('status', 'active')->limit(20000)
            ->get(['id', 'ulid', 'product_id', 'sku', 'barcode', 'supplier_sku', 'supplier_id', 'name', 'cost_price'])
            ->map(fn (ProductVariant $v) => [
                'v' => $v,
                'label' => trim(($v->product->name ?? '').($v->name ? ' '.$v->name : '')),
                'codes' => collect([Similar::code($v->sku), Similar::code($v->barcode), Similar::code($v->supplier_sku)])->filter(fn (string $c) => strlen($c) >= 3)->values()->all(),
            ]);
    }

    /**
     * @param  Collection<int, array{v: ProductVariant, label: string, codes: array<int, string>}>  $variants
     * @return array<string, mixed>
     */
    private function match(string $name, string $code, Collection $variants, ?int $supplierId): array
    {
        $c = Similar::code($code);
        $exact = strlen($c) >= 3 ? $variants->first(fn ($x) => in_array($c, $x['codes'], true)) : null;
        $scored = $variants->map(fn ($x) => $x + ['score' => Similar::score($name.' '.$code, $x['label'].' '.$x['v']->sku)
            + ($supplierId !== null && $x['v']->supplier_id === $supplierId ? 0.05 : 0)])
            ->filter(fn ($x) => $x['score'] >= self::MAYBE)->sortByDesc('score')->take(3)->values();
        $top = $exact ? ['v' => $exact['v'], 'label' => $exact['label'], 'score' => 1.0] : $scored->first();
        $status = $top === null ? 'new' : ($top['score'] >= self::SURE ? 'matched' : 'maybe');
        $out = fn ($x) => [
            'id' => $x['v']->ulid, 'sku' => $x['v']->sku, 'name' => $x['label'], 'score' => min(1, round($x['score'], 2)),
            'cost_price' => $x['v']->cost_price !== null ? (float) $x['v']->cost_price : null,
        ];

        return [
            'status' => $status,
            'variant' => $top ? $out($top) : null,
            'candidates' => $scored->map($out)->all(),
        ];
    }
}
