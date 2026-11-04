<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerMerge;
use App\Modules\Sales\Services\CustomerDuplicates;
use App\Modules\Sales\Services\CustomerMerger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Duplicate cards and their merge. Merging needs customers.merge; the cards are always of the current business.
 */
class CustomerMergeController extends Controller
{
    public function duplicates(CustomerDuplicates $duplicates): JsonResponse
    {
        $this->authorize('merge', Customer::class);

        return response()->json(['data' => $duplicates->groups()]);
    }

    public function preview(Request $request, Customer $customer, CustomerMerger $merger): JsonResponse
    {
        $this->authorize('merge', Customer::class);
        $other = $this->other($request);

        return response()->json(['data' => [
            'keep' => ['id' => $customer->ulid, 'code' => $customer->code, 'name' => $customer->name],
            'merge' => ['id' => $other->ulid, 'code' => $other->code, 'name' => $other->name],
            ...$merger->preview($customer, $other),
        ]]);
    }

    public function merge(Request $request, Customer $customer, CustomerMerger $merger): JsonResponse
    {
        $this->authorize('merge', Customer::class);
        $data = $request->validate([
            'take' => ['nullable', 'array'],
            'take.*' => ['string', Rule::in(CustomerMerger::FIELDS)],
        ]);
        $record = $merger->merge($customer, $this->other($request), $data['take'] ?? []);

        return response()->json(['data' => ['id' => $record->ulid, 'merged_code' => $record->merged_code, 'moved' => $record->moved]], 201);
    }

    /** The channel ids and past merges of a card. */
    public function links(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json(['data' => [
            'identities' => $customer->identities()->orderBy('source')->get()->map(fn ($i) => [
                'source' => $i->source, 'external_id' => $i->external_id, 'label' => $i->label, 'last_seen_at' => $i->last_seen_at?->toIso8601String(),
            ]),
            'merges' => CustomerMerge::query()->with('mergedBy:id,name')->where('customer_id', $customer->id)->latest('id')->get()->map(fn (CustomerMerge $m) => [
                'id' => $m->ulid, 'code' => $m->merged_code, 'name' => $m->merged_name, 'moved' => $m->moved,
                'by' => $m->mergedBy?->name, 'at' => $m->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    private function other(Request $request): Customer
    {
        $id = $request->validate(['source' => ['required', 'string', 'max:40']])['source'];

        return Customer::query()->where('ulid', $id)->firstOrFail();
    }
}
