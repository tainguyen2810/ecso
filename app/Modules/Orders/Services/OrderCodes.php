<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Mã đơn hàng (Cấu hình › Mã đơn hàng), like Sapo: prefix + running number without padding (SON50892). One counter
 * for every channel by default, or one prefix and counter per source (manual, shopee, tiktok, woocommerce…). Off
 * until the shop presses "Bắt đầu dùng mã mới": that numbers the orders that have no code yet, oldest first, then
 * every new order takes the next number. Counters live in document_sequences under "#" + prefix.
 */
class OrderCodes
{
    /** Sources that can get their own prefix; any other marketplace uses the shared one. */
    public const SOURCES = ['manual', 'shopee', 'tiktok', 'woocommerce'];

    public const PREFIX_PATTERN = '/^[A-Z]{1,6}$/';

    public static function enabled(Tenant $tenant): bool
    {
        return (bool) TenantSettings::for($tenant)->get('order_codes.enabled');
    }

    /** The prefix an order of this source takes. */
    public static function prefixFor(Tenant $tenant, string $marketplace): string
    {
        $settings = TenantSettings::for($tenant);
        $shared = (string) $settings->get('order_codes.prefix');
        if ($settings->get('order_codes.mode') !== 'per_source') {
            return $shared;
        }
        if (! in_array($marketplace, self::SOURCES, true)) {
            return $shared;
        }

        return (string) ($settings->get("order_codes.prefixes.{$marketplace}") ?: $shared);
    }

    /** Next code for a new order of this source, or null while the shop has not started its numbering. */
    public function forNewOrder(Tenant $tenant, string $marketplace): ?string
    {
        return self::enabled($tenant) ? $this->take($tenant->id, self::prefixFor($tenant, $marketplace)) : null;
    }

    /**
     * Gives the order a code if the shop numbers its orders and the order has none. Runs inside the caller's
     * transaction (the counter row is locked until it commits, so two orders never share a number).
     */
    public function assign(Order $order, Tenant $tenant): void
    {
        if ($order->code !== null || ! self::enabled($tenant)) {
            return;
        }
        $order->forceFill(['code' => $this->take($tenant->id, self::prefixFor($tenant, (string) $order->marketplace))])->save();
    }

    /** Whether a code typed by hand is free in the shop. */
    public static function isFree(int $tenantId, string $code, ?int $exceptOrderId = null): bool
    {
        return ! Order::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)
            ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))->exists();
    }

    /** The next number this prefix would give (not taken). */
    public function peek(int $tenantId, string $prefix): int
    {
        $last = (int) DB::table('document_sequences')->where('tenant_id', $tenantId)->where('prefix', self::key($prefix))->value('last_value');

        return max($last, $this->highestUsed($tenantId, $prefix)) + 1;
    }

    /**
     * Highest number already used with this prefix by any order of the shop (Sapo's SON50891 → 50891), so a new
     * counter never hands out a number that exists.
     */
    public function highestUsed(int $tenantId, string $prefix): int
    {
        $len = strlen($prefix);

        return (int) Order::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('code', 'like', $prefix.'%')
            ->whereRaw('SUBSTRING(code, ?) REGEXP ?', [$len + 1, '^[0-9]{1,15}$'])
            ->max(DB::raw('CAST(SUBSTRING(code, '.($len + 1).') AS UNSIGNED)'));
    }

    /** Sets the next number of a prefix; it may not go back to a number already used. */
    public function setNext(int $tenantId, string $prefix, int $next): void
    {
        $used = $this->highestUsed($tenantId, $prefix);
        if ($next <= $used) {
            throw ValidationException::withMessages(['next' => "Số tiếp theo của {$prefix} phải lớn hơn {$used} (đã có đơn {$prefix}{$used})."]);
        }
        DB::table('document_sequences')->updateOrInsert(['tenant_id' => $tenantId, 'prefix' => self::key($prefix)], ['last_value' => $next - 1]);
    }

    /**
     * Numbers every order of the shop that has no code yet, in the order they were placed, and turns the numbering
     * on. Returns how many orders got a code and the first / last one.
     *
     * @return array{count: int, first: string|null, last: string|null}
     */
    public function start(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant) {
            $settings = $tenant->settings ?? [];
            $settings['order_codes'] = array_merge($settings['order_codes'] ?? [], ['enabled' => true, 'started_at' => now()->toIso8601String()]);
            $tenant->settings = $settings;
            $tenant->save();

            $count = 0;
            $first = $last = null;
            // Ids first: the loop fills the very column the query filters on.
            $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('code')
                ->orderByRaw('COALESCE(placed_at, created_at)')->orderBy('id')->toBase()->get(['id', 'marketplace']);
            foreach ($orders as $order) {
                $code = $this->take($tenant->id, self::prefixFor($tenant, (string) $order->marketplace));
                Order::withoutGlobalScopes()->whereKey($order->id)->update(['code' => $code]);
                $first ??= $code;
                $last = $code;
                $count++;
            }

            $started = ['count' => $count, 'first' => $first, 'last' => $last];
            $settings['order_codes']['started'] = $started;
            $tenant->settings = $settings;
            $tenant->save();

            return $started;
        });
    }

    private function take(int $tenantId, string $prefix): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OrderCodes must run inside a transaction.');
        }
        $key = self::key($prefix);
        $exists = DB::table('document_sequences')->where('tenant_id', $tenantId)->where('prefix', $key)->exists();
        if (! $exists) {
            // First use: start after the highest number the shop already has (orders brought over from Sapo).
            DB::table('document_sequences')->insertOrIgnore(['tenant_id' => $tenantId, 'prefix' => $key, 'last_value' => $this->highestUsed($tenantId, $prefix)]);
        }
        $current = (int) DB::table('document_sequences')->where('tenant_id', $tenantId)->where('prefix', $key)->lockForUpdate()->value('last_value');
        $next = $current + 1;
        // A number typed by hand on an order may already hold the next value: skip past it.
        while (! self::isFree($tenantId, $prefix.$next)) {
            $next++;
        }
        DB::table('document_sequences')->where('tenant_id', $tenantId)->where('prefix', $key)->update(['last_value' => $next]);

        return $prefix.$next;
    }

    private static function key(string $prefix): string
    {
        return '#'.$prefix;
    }
}
