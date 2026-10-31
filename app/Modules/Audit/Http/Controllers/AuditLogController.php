<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($context->can('audit.view'), 403);

        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->where('tenant_id', $context->id())
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->orderByDesc('id')
            ->cursorPaginate(min((int) $request->integer('per_page', 50), 100));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? ['name' => $log->actor->name, 'email' => $log->actor->email] : null,
                'subject_type' => $log->subject_type,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'next_cursor' => $logs->nextCursor()?->encode(),
        ]);
    }
}
