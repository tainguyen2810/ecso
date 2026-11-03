<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use App\Modules\Purchasing\Http\Resources\SupplierPaymentResource;
use App\Modules\Purchasing\Http\Resources\SupplierResource;
use App\Modules\Purchasing\Http\Resources\SupplierReturnResource;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierExporter;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Supplier::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'debt' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'newest', 'debt', 'purchased'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = Supplier::query()->withBalance();
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($w) => $w->where('suppliers.name', 'like', "%{$q}%")->orWhere('suppliers.code', 'like', "%{$q}%")->orWhere('suppliers.phone', 'like', "%{$q}%"));
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== null) {
            $query->where('suppliers.is_active', $request->boolean('active'));
        }
        if ($request->boolean('debt')) {
            $query->having('debt', '>', 0);
        }
        match ($filters['sort'] ?? 'name') {
            'newest' => $query->orderByDesc('suppliers.id'),
            'debt' => $query->orderByDesc('debt'),
            'purchased' => $query->orderByDesc('purchased'),
            default => $query->orderBy('suppliers.name'),
        };

        return SupplierResource::collection($query->paginate((int) ($filters['per_page'] ?? 30)));
    }

    public function export(SupplierExporter $exporter): StreamedResponse
    {
        $this->authorize('viewAny', Supplier::class);

        return $exporter->stream();
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);
        $withBalance = Supplier::query()->withBalance()->whereKey($supplier->id)->firstOrFail();

        return (new SupplierResource($withBalance))->additional(['data' => [
            'orders' => PurchaseOrderResource::collection($supplier->orders()->with('warehouse')->orderByDesc('id')->limit(30)->get()),
            'payments' => SupplierPaymentResource::collection($supplier->payments()->with(['order', 'creator'])->orderByDesc('paid_at')->limit(30)->get()),
            'returns' => SupplierReturnResource::collection($supplier->returns()->with(['order'])->orderByDesc('id')->limit(30)->get()),
        ]])->response();
    }

    public function store(Request $request, SupplierService $suppliers): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        return (new SupplierResource($suppliers->create($this->validated($request))))->response()->setStatusCode(201);
    }

    public function update(Request $request, Supplier $supplier, SupplierService $suppliers): SupplierResource
    {
        $this->authorize('update', $supplier);

        return new SupplierResource($suppliers->update($supplier, $this->validated($request, partial: true)));
    }

    public function destroy(Supplier $supplier, SupplierService $suppliers): Response
    {
        $this->authorize('delete', $supplier);
        $suppliers->delete($supplier);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_code' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
