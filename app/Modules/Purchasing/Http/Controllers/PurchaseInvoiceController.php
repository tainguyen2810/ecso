<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Services\InvoiceReader;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseInvoiceExporter;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Nhập hàng › Hoá đơn mua vào: e-invoices from suppliers, checked and turned into purchase orders. */
class PurchaseInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $f = $request->validate([
            'tab' => ['nullable', 'in:all,open,review,linked,ignored'],
            'month' => ['nullable', 'date_format:Y-m'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $base = $this->scoped($f['month'] ?? null, $f['q'] ?? null);
        $counts = [
            'all' => (clone $base)->count(),
            'open' => (clone $base)->where('status', 'open')->count(),
            'review' => $this->review(clone $base)->count(),
            'linked' => (clone $base)->where('status', 'linked')->count(),
            'ignored' => (clone $base)->where('status', 'ignored')->count(),
        ];
        $kept = (clone $base)->where('status', '!=', 'ignored');
        $deductible = (clone $kept)->where('kind', 'vat')->where(function (Builder $q) {
            foreach (PurchaseInvoice::BLOCKING as $issue) {
                $q->whereJsonDoesntContain('issues', $issue);
            }
        });
        $list = match ($f['tab'] ?? 'all') {
            'open' => (clone $base)->where('status', 'open'),
            'review' => $this->review(clone $base),
            'linked' => (clone $base)->where('status', 'linked'),
            'ignored' => (clone $base)->where('status', 'ignored'),
            default => clone $base,
        };
        $page = $list->with('purchaseOrder:id,ulid,number', 'supplier:id,ulid,name')->orderByDesc('issued_on')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseInvoice $i) => $this->row($i))->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'counts' => $counts,
                'summary' => [
                    'invoices' => (clone $kept)->count(),
                    'total' => (string) (clone $kept)->sum('total'),
                    'vat' => (string) (clone $deductible)->sum('vat_amount'),
                    'deductible' => (clone $deductible)->count(),
                    'suppliers' => (clone $kept)->distinct()->count('seller_tax_code'),
                    'open_total' => (string) (clone $base)->where('status', 'open')->sum('total'),
                ],
            ],
        ]);
    }

    public function import(Request $request, PurchaseInvoiceService $service): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:20480', function (string $attr, mixed $file, \Closure $fail) {
                $ext = strtolower($file->getClientOriginalExtension());
                if (! in_array($ext, ['xml', 'zip'], true)) {
                    $fail('Chỉ nhận file hoá đơn .xml hoặc .zip.');
                }
            }],
        ]);

        return response()->json(['data' => $service->import($request->file('files'), $request->user()?->id)]);
    }

    public function show(PurchaseInvoice $purchaseInvoice, PurchaseInvoiceService $service, InvoiceReader $reader): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $purchaseInvoice->load('purchaseOrder:id,ulid,number', 'supplier:id,ulid,name');

        return response()->json(['data' => $this->row($purchaseInvoice) + [
            'lines' => $purchaseInvoice->lines,
            'draft' => $reader->draft($service->reading($purchaseInvoice)),
        ]]);
    }

    public function ignore(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;
        if ($purchaseInvoice->status === 'linked') {
            throw ValidationException::withMessages(['status' => 'Hoá đơn đã vào phiếu nhập.']);
        }
        $purchaseInvoice->forceFill(['status' => 'ignored', 'note' => $note])->save();

        return response()->json(['data' => $this->row($purchaseInvoice)]);
    }

    public function restore(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        if ($purchaseInvoice->status === 'ignored') {
            $purchaseInvoice->forceFill(['status' => 'open', 'note' => null])->save();
        }

        return response()->json(['data' => $this->row($purchaseInvoice)]);
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        if ($purchaseInvoice->status === 'linked') {
            throw ValidationException::withMessages(['status' => 'Hoá đơn đã vào phiếu nhập, không xoá được.']);
        }
        $purchaseInvoice->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function export(Request $request, PurchaseInvoiceExporter $exporter): StreamedResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $f = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return $exporter->stream($f['month'] ?? null);
    }

    /** @return Builder<PurchaseInvoice> */
    private function scoped(?string $month, ?string $q): Builder
    {
        $query = PurchaseInvoice::query();
        if ($month) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
            $query->whereBetween('issued_on', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
        }
        if ($q !== null && trim($q) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($q)).'%';
            $query->where(fn (Builder $w) => $w->where('seller_name', 'like', $like)->orWhere('seller_tax_code', 'like', $like)->orWhere('number', 'like', $like));
        }

        return $query;
    }

    /**
     * @param  Builder<PurchaseInvoice>  $query
     * @return Builder<PurchaseInvoice>
     */
    private function review(Builder $query): Builder
    {
        return $query->where('status', 'open')->whereRaw('JSON_LENGTH(issues) > 0');
    }

    /** @return array<string, mixed> */
    private function row(PurchaseInvoice $i): array
    {
        return [
            'id' => $i->ulid,
            'kind' => $i->kind,
            'series' => $i->series,
            'number' => $i->number,
            'issued_on' => $i->issued_on?->toDateString(),
            'authority_code' => $i->authority_code,
            'seller' => ['name' => $i->seller_name, 'tax_code' => $i->seller_tax_code, 'address' => $i->seller_address],
            'buyer' => ['name' => $i->buyer_name, 'tax_code' => $i->buyer_tax_code],
            'subtotal' => (string) $i->subtotal,
            'vat_amount' => (string) $i->vat_amount,
            'vat_rate' => $i->vat_rate !== null ? (float) $i->vat_rate : null,
            'total' => (string) $i->total,
            'line_count' => count($i->lines),
            'issues' => array_values($i->issues ?? []),
            'blocked' => $i->blocked(),
            'status' => $i->status,
            'note' => $i->note,
            'supplier' => $i->supplier ? ['id' => $i->supplier->ulid, 'name' => $i->supplier->name] : null,
            'purchase_order' => $i->purchaseOrder ? ['id' => $i->purchaseOrder->ulid, 'number' => $i->purchaseOrder->number] : null,
        ];
    }
}
