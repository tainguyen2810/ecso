<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerDebtEntry;
use App\Modules\Sales\Services\CustomerDebtEntries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Opening balances and adjustments of a customer's debt (permission customers.debt_adjust).
 */
class CustomerDebtController extends Controller
{
    public function store(Request $request, Customer $customer, CustomerDebtEntries $entries): JsonResponse
    {
        $this->authorize('adjustDebt', $customer);
        $data = $request->validate([
            'kind' => ['required', Rule::in([CustomerDebtEntry::OPENING, CustomerDebtEntry::ADJUSTMENT])],
            'amount' => ['required', 'numeric', 'min:-9999999999', 'max:9999999999'],
            'reason' => ['required', 'string', 'max:500'],
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date'],
        ], ['reason.required' => 'Nhập lý do.']);

        return response()->json(['data' => $this->present($entries->record($customer, $data))], 201);
    }

    public function void(Request $request, Customer $customer, CustomerDebtEntry $entry, CustomerDebtEntries $entries): JsonResponse
    {
        $this->authorize('adjustDebt', $customer);
        abort_unless($entry->customer_id === $customer->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Nhập lý do hủy.']);

        return response()->json(['data' => $this->present($entries->void($entry, $data['reason']))]);
    }

    /** @return array<string, mixed> */
    private function present(CustomerDebtEntry $entry): array
    {
        return [
            'id' => $entry->ulid, 'number' => $entry->number, 'kind' => $entry->kind, 'amount' => (string) $entry->amount,
            'entry_date' => $entry->entry_date->toDateString(), 'due_date' => $entry->due_date?->toDateString(), 'reason' => $entry->reason,
            'voided_at' => $entry->voided_at?->toIso8601String(), 'void_reason' => $entry->void_reason,
        ];
    }
}
