<?php

namespace App\Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Services\AssistantException;
use App\Modules\Assistant\Services\EzChatAi;
use App\Modules\Assistant\Services\InvoiceReader;
use App\Modules\Assistant\Services\ProductReader;
use App\Modules\Catalog\Models\Product;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "AI trong Bán hàng": read a supplier invoice (Nhập hàng › Chụp hoá đơn) or product photos (Sản phẩm › Tạo từ ảnh). */
class AssistantController extends Controller
{
    public const MAX_INVOICE_PAGES = 6;

    public const MAX_PRODUCT_PHOTOS = 12;

    public function __construct(private EzChatAi $ai, private TenantContext $context) {}

    public function status(): JsonResponse
    {
        $tenant = $this->context->tenant();
        $why = $this->ai->unavailable($tenant);

        return response()->json(['data' => ['available' => $why === null, 'reason' => $why]]);
    }

    public function invoice(Request $request, InvoiceReader $reader): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_INVOICE_PAGES],
            'images.*' => ['required', 'string', 'max:4000000'],
        ]);

        return $this->run(function () use ($data, $reader) {
            $r = $this->ai->read($this->context->tenant(), 'invoice', $this->clean($data['images']));

            return ['draft' => $reader->draft($r['data']), 'credits' => $r['credits']];
        });
    }

    public function products(Request $request, ProductReader $reader): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCT_PHOTOS],
            'images.*' => ['required', 'string', 'max:4000000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'markup' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        return $this->run(function () use ($data, $reader) {
            $images = $this->clean($data['images']);
            $extra = ['categories' => array_slice($reader->categoryPaths(), 0, 400)];
            if (filled($data['note'] ?? null)) {
                $extra['note'] = (string) $data['note'];
            }
            $r = $this->ai->read($this->context->tenant(), 'product', $images, $extra);

            return ['products' => $reader->suggestions($r['data'], count($images), (float) ($data['markup'] ?? 25)), 'credits' => $r['credits']];
        });
    }

    /**
     * "data:image/jpeg;base64,…" or bare base64.
     *
     * @param  list<string>  $images
     * @return list<string>
     */
    private function clean(array $images): array
    {
        return array_map(fn ($i) => (string) preg_replace('/^data:image\/[a-z]+;base64,/', '', $i), $images);
    }

    /** @param  callable(): array<string, mixed>  $work */
    private function run(callable $work): JsonResponse
    {
        try {
            return response()->json(['data' => $work()]);
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->reason === 'not_linked' ? 409 : 422);
        }
    }
}
