<?php

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Support\TenantContext;

it('shows audit logs only to users with audit.view and only for their tenant', function () {
    [$a, $ownerA] = tenantWithOwner('A');
    [$b] = tenantWithOwner('B');
    $viewer = addMember($a, Role::VIEWER);

    actingInTenant($viewer, $a)->getJson('/api/v1/audit-logs')->assertForbidden();

    $logs = actingInTenant($ownerA, $a)->getJson('/api/v1/audit-logs')->assertOk()->json('data');
    $tenantIds = AuditLog::query()->whereIn('id', collect($logs)->pluck('id'))->pluck('tenant_id')->unique()->all();

    expect($logs)->not->toBeEmpty()->and($tenantIds)->toBe([$a->id]);
});

it('redacts secrets in audit changes', function () {
    [$tenant] = tenantWithOwner();

    $log = TenantContext::run($tenant, fn () => app(AuditLogger::class)->record('test', null, [
        'password' => 'p', 'nested' => ['access_token' => 't'], 'name' => 'ok',
    ]));

    expect($log->fresh()->changes)->toEqual(['password' => '[REDACTED]', 'nested' => ['access_token' => '[REDACTED]'], 'name' => 'ok']);
});

it('is append-only', function () {
    tenantWithOwner();
    AuditLog::query()->first()->delete();
})->throws(LogicException::class);
