<?php

namespace App\Modules\Messaging\Push;

use App\Modules\Messaging\Models\PushSubscription;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One message to the phones of the current store's members who hold a permission (Đợt 14–15: đơn lỗ, bản tin sáng).
 * Pushes carry no payload: the message is kept on each subscription and the service worker reads it when woken.
 */
class PushNotifier
{
    public function __construct(private readonly WebPush $push, private readonly TenantContext $context) {}

    /**
     * @param  bool  $brief  only phones that kept "Bản tin 8h sáng" on
     * @return int phones woken
     */
    public function send(string $permission, string $title, string $body, string $url, bool $brief = false): int
    {
        $users = TenantUser::query()->withoutGlobalScopes()->with('role')
            ->where('tenant_id', $this->context->id())->where('status', TenantUser::STATUS_ACTIVE)->get()
            ->filter(fn (TenantUser $m) => $m->role !== null && in_array($permission, $m->role->permissionKeys(), true))
            ->pluck('user_id')->all();
        if ($users === []) {
            return 0;
        }

        $sent = 0;
        foreach (PushSubscription::query()->whereIn('user_id', $users)->when($brief, fn ($q) => $q->where('morning_brief', true))->get() as $sub) {
            $sub->forceFill(['last_title' => mb_substr($title, 0, 150), 'last_body' => mb_substr($body, 0, 300), 'last_url' => $url, 'last_sent_at' => now()])->save();
            try {
                $this->push->wake($sub->endpoint) ? $sent++ : $sub->delete();
            } catch (Throwable $e) {
                Log::warning('push failed', ['tenant' => $this->context->id(), 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
