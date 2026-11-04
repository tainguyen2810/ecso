<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerTask;
use App\Modules\Sales\Services\CustomerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Follow-ups on customers. Anyone who can see a customer can plan and close a follow-up on them (like a note).
 */
class CustomerTaskController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly CustomerService $customers) {}

    /** Open follow-ups across customers: due today and late first; `mine` keeps the current member's. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $data = $request->validate(['mine' => ['nullable', 'boolean'], 'due' => ['nullable', Rule::in(['late', 'today', 'week', 'all'])]]);
        $tasks = CustomerTask::query()->with(['customer:id,ulid,code,name', 'assignee:id,name'])->whereNull('done_at')
            ->when(! empty($data['mine']), fn (Builder $q) => $q->where('assigned_user_id', $request->user()->id))
            ->when(($data['due'] ?? 'week') !== 'all', fn (Builder $q) => $q->where('due_at', '<=', match ($data['due'] ?? 'week') {
                'late' => now(), 'today' => now()->endOfDay(), default => now()->addWeek()->endOfDay(),
            }))
            ->orderBy('due_at')->limit(200)->get();

        return response()->json(['data' => $tasks->map(fn (CustomerTask $t) => $this->present($t))]);
    }

    public function forCustomer(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $tasks = CustomerTask::query()->with('assignee:id,name')->where('customer_id', $customer->id)
            ->orderByRaw('done_at IS NOT NULL')->orderBy('due_at')->limit(100)->get();

        return response()->json(['data' => $tasks->map(fn (CustomerTask $t) => $this->present($t))]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_at' => ['required', 'date'],
            'assigned_user_id' => ['nullable', 'string', 'max:40'],
        ]);
        $task = new CustomerTask(['title' => $data['title'], 'due_at' => $data['due_at']]);
        $task->forceFill([
            'customer_id' => $customer->id, 'created_by' => $request->user()->id,
            'assigned_user_id' => $this->customers->member($data['assigned_user_id'] ?? null)->id ?? $request->user()->id,
        ])->save();
        $this->audit->record('customer.task_created', $customer, ['title' => $task->title, 'due_at' => $task->due_at->toIso8601String()]);

        return response()->json(['data' => $this->present($task->load('assignee:id,name'))], 201);
    }

    public function complete(Request $request, Customer $customer, CustomerTask $task): JsonResponse
    {
        $this->authorize('view', $customer);
        abort_unless($task->customer_id === $customer->id, 404);
        if ($task->done_at === null) {
            $task->forceFill(['done_at' => now(), 'done_by' => $request->user()->id])->save();
            $this->audit->record('customer.task_done', $customer, ['title' => $task->title]);
        }

        return response()->json(['data' => $this->present($task->load('assignee:id,name'))]);
    }

    public function destroy(Request $request, Customer $customer, CustomerTask $task): JsonResponse
    {
        $this->authorize('view', $customer);
        abort_unless($task->customer_id === $customer->id, 404);
        if ($task->created_by !== $request->user()->id && ! $request->user()->can('update', $customer)) {
            throw ValidationException::withMessages(['task' => 'Chỉ người tạo hoặc người quản lý khách mới xóa được việc này.']);
        }
        $task->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(CustomerTask $t): array
    {
        return [
            'id' => $t->ulid, 'title' => $t->title, 'due_at' => $t->due_at->toIso8601String(), 'done_at' => $t->done_at?->toIso8601String(),
            'late' => $t->done_at === null && $t->due_at->isPast(),
            'assignee' => $t->relationLoaded('assignee') ? $t->assignee?->name : null,
            'customer' => $t->relationLoaded('customer') && $t->customer ? ['id' => $t->customer->ulid, 'code' => $t->customer->code, 'name' => $t->customer->name] : null,
        ];
    }
}
