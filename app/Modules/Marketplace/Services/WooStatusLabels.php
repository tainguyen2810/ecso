<?php

namespace App\Modules\Marketplace\Services;

use App\Modules\Marketplace\Connectors\WooCommerce\WooClient;
use App\Modules\Marketplace\Connectors\WooCommerce\WooCommerceConnector;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Orders\Domain\OrderStatus;
use Illuminate\Support\Facades\Cache;

/**
 * The names a WooCommerce store shows for its order statuses, including the ones a plugin added ("Đang giao hàng"
 * for wc-dang-giao-hang). Woo only sends the slug on an order; the names come from reports/orders/totals, which
 * lists every registered status. They are kept in the store's settings (status_labels) so screens can show the
 * website's own name for a status ECSO has not mapped yet, and the store page can suggest a mapping.
 */
class WooStatusLabels
{
    /** Minutes between two reads of the same store's status list. */
    private const THROTTLE = 10;

    /**
     * Re-reads the names when a status has no name yet (at most every few minutes per store). Never throws: names are
     * only for display.
     *
     * @return array<string, string> slug => name
     */
    public function ensure(MarketplaceAccount $account, ?string $raw = null): array
    {
        $labels = $account->statusLabels();
        if ($account->marketplace !== WooCommerceConnector::MARKETPLACE || ! $account->isActive()) {
            return $labels;
        }
        $slug = $raw === null ? null : explode(':', $raw)[0];
        if (($slug !== null && isset($labels[$slug])) || ($slug === null && $labels !== [])) {
            return $labels;
        }
        if (! Cache::add("woo-status-labels:{$account->id}", 1, now()->addMinutes(self::THROTTLE))) {
            return $labels;
        }

        try {
            $rows = WooClient::forAccount($account)->get('reports/orders/totals');
        } catch (\Throwable) {
            return $labels;
        }
        $fresh = [];
        foreach ($rows as $row) {
            $key = preg_replace('/^wc-/', '', (string) ($row['slug'] ?? ''));
            $name = trim(strip_tags((string) ($row['name'] ?? '')));
            if ($key !== '' && $name !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', $key)) {
                $fresh[$key] = mb_substr($name, 0, 60);
            }
        }
        if ($fresh !== [] && $fresh !== $labels) {
            $account->forceFill(['settings' => array_merge((array) $account->settings, ['status_labels' => $fresh])])->save();
        }

        return $fresh ?: $labels;
    }

    /** The website's name for a raw status, or null. "processing:packed" stages are ECSO's own and have none. */
    public static function labelFor(?MarketplaceAccount $account, ?string $raw): ?string
    {
        if ($account === null || blank($raw) || str_contains($raw, ':')) {
            return null;
        }

        return $account->statusLabels()[$raw] ?? null;
    }

    /**
     * A likely ECSO status for a status a plugin added, from its name and slug. Only offered on the store page for
     * the seller to confirm; orders are never moved on a guess.
     */
    public static function suggest(string $raw, ?string $label): ?string
    {
        $text = mb_strtolower(trim(($label ?? '').' '.str_replace(['-', '_'], ' ', $raw)));
        $plain = self::fold($text);
        $rules = [
            // Most specific first: "giao thất bại" before "giao", "đã giao" before "đang giao".
            OrderStatus::FAILED_DELIVERY => ['giao that bai', 'giao khong thanh cong', 'delivery failed', 'failed delivery', 'undelivered'],
            OrderStatus::RETURN_REQUESTED => ['yeu cau tra', 'yeu cau hoan', 'return request'],
            OrderStatus::RETURNED => ['hoan hang', 'tra hang', 'hoan tien', 'returned', 'refund'],
            OrderStatus::CANCELLED => ['huy', 'cancel', 'that bai', 'failed'],
            OrderStatus::DELIVERED => ['da giao', 'giao thanh cong', 'delivered'],
            OrderStatus::IN_TRANSIT => ['dang giao', 'van chuyen', 'giao hang', 'shipping', 'shipped', 'in transit', 'transit'],
            OrderStatus::HANDED_OVER => ['ban giao', 'da gui', 'handed'],
            OrderStatus::PACKED => ['dong goi', 'cho lay hang', 'packed', 'packing'],
            OrderStatus::COMPLETED => ['hoan thanh', 'hoan tat', 'complete'],
            OrderStatus::UNPAID => ['cho thanh toan', 'chua thanh toan', 'unpaid', 'awaiting payment'],
            OrderStatus::READY_TO_PACK => ['dang xu ly', 'xu ly', 'processing', 'da xac nhan', 'confirmed'],
            OrderStatus::PENDING_CONFIRMATION => ['cho xac nhan', 'tam giu', 'cho', 'pending', 'on hold', 'hold'],
        ];
        foreach ($rules as $status => $words) {
            foreach ($words as $w) {
                if (preg_match('/(^|\s)'.preg_quote($w, '/').'(\s|$)/', $plain)) {
                    return $status;
                }
            }
        }

        return null;
    }

    private static function fold(string $text): string
    {
        $text = str_replace(['đ', 'Đ'], 'd', $text);
        $ascii = class_exists(\Normalizer::class) ? preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D)) : $text;

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', (string) $ascii)));
    }
}
