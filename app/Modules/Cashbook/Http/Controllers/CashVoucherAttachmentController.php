<?php

namespace App\Modules\Cashbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashVoucher;
use App\Modules\Cashbook\Models\CashVoucherAttachment;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files kept with a voucher: photos of invoices, bank transfer slips (PDF or image, 5 MB each, 10 per voucher).
 * Stored on the private disk under the business's folder and only served through here.
 */
class CashVoucherAttachmentController extends Controller
{
    public const MAX_FILES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(CashVoucher $cashVoucher): JsonResponse
    {
        $this->authorize('view', CashVoucher::class);

        return response()->json(['data' => $this->list($cashVoucher)]);
    }

    public function store(Request $request, CashVoucher $cashVoucher, TenantContext $context): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,heic,pdf']]);
        if (CashVoucherAttachment::query()->where('cash_voucher_id', $cashVoucher->id)->whereNull('removed_at')->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => 'Mỗi phiếu đính kèm tối đa '.self::MAX_FILES.' file.']);
        }
        $file = $request->file('file');
        $path = $file->storeAs("tenants/{$context->tenant()->ulid}/vouchers/{$cashVoucher->ulid}", Str::random(40).'.'.$file->extension(), CashVoucherAttachment::DISK);

        $attachment = CashVoucherAttachment::query()->create([
            'cash_voucher_id' => $cashVoucher->id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);
        $this->audit->record('cash.attachment_added', $cashVoucher, ['number' => $cashVoucher->number, 'file' => $attachment->original_name]);

        return response()->json(['data' => $this->list($cashVoucher)], 201);
    }

    public function show(CashVoucherAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', CashVoucher::class);
        abort_if($attachment->removed_at !== null, 404);

        return Storage::disk(CashVoucherAttachment::DISK)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Only hides the file: the voucher's evidence is never deleted. */
    public function destroy(CashVoucherAttachment $attachment): JsonResponse
    {
        $this->authorize('create', CashVoucher::class);
        if ($attachment->removed_at === null) {
            $attachment->forceFill(['removed_at' => now(), 'removed_by' => Auth::id()])->save();
            $voucher = $attachment->voucher;
            $this->audit->record('cash.attachment_removed', $voucher, ['number' => $voucher?->number, 'file' => $attachment->original_name]);
        }

        return response()->json(['data' => $attachment->voucher ? $this->list($attachment->voucher) : []]);
    }

    /** @return list<array<string, mixed>> */
    private function list(CashVoucher $voucher): array
    {
        return CashVoucherAttachment::query()->with('uploader:id,name')->where('cash_voucher_id', $voucher->id)->whereNull('removed_at')->orderBy('id')->get()
            ->map(fn (CashVoucherAttachment $a) => [
                'id' => $a->ulid,
                'name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
                'is_image' => str_starts_with($a->mime, 'image/'),
                'uploaded_by' => $a->uploader?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all();
    }
}
