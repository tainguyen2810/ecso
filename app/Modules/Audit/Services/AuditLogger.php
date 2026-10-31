<?php

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogger
{
    private const REDACTED_KEYS = ['password', 'token', 'token_hash', 'access_token', 'refresh_token', 'secret'];

    public function __construct(private readonly TenantContext $context, private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], ?int $tenantId = null, ?int $actorId = null): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill([
            'tenant_id' => $tenantId ?? ($this->context->has() ? $this->context->id() : null),
            'actor_user_id' => $actorId ?? $this->request->user()?->getKey(),
            'actor_type' => $this->request->user() || $actorId ? 'user' : 'system',
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ? $this->redact($changes) : null,
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
        ])->save();

        return $log;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach (Arr::dot($data) as $key => $value) {
            foreach (self::REDACTED_KEYS as $sensitive) {
                if (Str::contains(Str::lower($key), $sensitive)) {
                    Arr::set($data, $key, '[REDACTED]');
                }
            }
        }

        return $data;
    }
}
