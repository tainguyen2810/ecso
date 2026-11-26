<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Support\TenantSettings;
use App\Shared\Support\ReportRange;

/**
 * "Chữ in thêm lên phiếu sàn": what the shop chose (Cấu hình › Mẫu in) to stamp onto the Shopee / TikTok shipping
 * label, filled in for one order. The browser writes it into the blank space of the marketplace's own label; the
 * label itself is never moved, resized or covered. Everything is off by default, so the label prints as the
 * marketplace made it.
 */
class LabelExtras
{
    public const SIZES = ['sm', 'md', 'lg'];

    public const VARIABLES = ['ten_khach', 'ma_don', 'tong_tien', 'ngay_dat', 'ten_shop'];

    /** At most this many SKU lines; the rest is one "+N mã khác" line. */
    public const PICKLIST_MAX = 8;

    /**
     * @param  array<string, mixed>|null  $config  print.label_extras; read from the settings when null
     * @return array{picklist: array<int, array{sku: string|null, name: string, quantity: int}>|null, note: string|null, warehouse: string|null, notice: array{lines: array<int, string>, box: bool}|null, size: string}|null
     */
    public function for(Order $order, ?array $config = null): ?array
    {
        $config ??= (array) TenantSettings::current()->get('print.label_extras');
        $accounts = $config['accounts'] ?? null;
        if (is_array($accounts) && ! in_array($order->account?->ulid, $accounts, true)) {
            return null;
        }

        $extras = [
            'picklist' => ($config['picklist'] ?? false) ? $this->picklist($order) : null,
            'note' => ($config['note'] ?? false) ? $this->note($order) : null,
            'warehouse' => ($config['warehouse'] ?? false) ? $order->warehouse?->name : null,
            'notice' => ($config['notice'] ?? false) ? $this->notice($order, (string) ($config['notice_text'] ?? ''), (bool) ($config['notice_box'] ?? true)) : null,
            'size' => in_array($config['size'] ?? null, self::SIZES, true) ? $config['size'] : 'md',
        ];

        return $extras['picklist'] || $extras['note'] || $extras['warehouse'] || $extras['notice'] ? $extras : null;
    }

    /** @return array<int, array{sku: string|null, name: string, quantity: int}>|null */
    private function picklist(Order $order): ?array
    {
        $order->loadMissing('items.variant.product');
        $rows = [];
        foreach ($order->items as $item) {
            if ($item->mapping_status === OrderItem::SERVICE) {
                continue;
            }
            $quantity = max(0, $item->quantity - (int) $item->refunded_quantity);
            if ($quantity === 0) {
                continue;
            }
            $variant = $item->variant;
            $key = $variant ? 'v'.$variant->id : 's'.mb_strtolower(($item->seller_sku ?? '').'|'.$item->name.'|'.$item->variant_name);
            $rows[$key] ??= [
                'sku' => $variant->sku ?? $item->seller_sku,
                'name' => $variant ? $variant->displayName() : trim($item->name.($item->variant_name ? ' - '.$item->variant_name : '')),
                'quantity' => 0,
            ];
            $rows[$key]['quantity'] += $quantity;
        }

        return $rows === [] ? null : array_values($rows);
    }

    private function note(Order $order): ?string
    {
        $order->loadMissing('notes');
        $parts = [];
        if (filled($order->buyer_note)) {
            $parts[] = 'Khách nhắn: '.trim((string) $order->buyer_note);
        }
        $internal = $order->notes->sortByDesc('id')->take(2)->pluck('body')->map(fn ($b) => trim((string) $b))->filter()->reverse()->implode('. ');
        if ($internal !== '') {
            $parts[] = 'Ghi chú: '.$internal;
        }

        return $parts === [] ? null : mb_strimwidth(implode(' · ', $parts), 0, 300, '…');
    }

    /** @return array{lines: array<int, string>, box: bool}|null */
    private function notice(Order $order, string $text, bool $box): ?array
    {
        $values = [
            'ten_khach' => trim((string) $order->buyer_name) ?: 'quý khách',
            'ma_don' => (string) $order->external_order_id,
            'tong_tien' => number_format((float) $order->total, 0, ',', '.').'đ',
            'ngay_dat' => $order->placed_at?->setTimezone(ReportRange::timezone())->format('d/m/Y') ?? '',
            'ten_shop' => (string) $order->account?->shop_name,
        ];
        $filled = preg_replace_callback('/\{(\w+)\}/u', fn ($m) => $values[$m[1]] ?? $m[0], $text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $filled) ?: []), fn ($l) => $l !== ''));

        return $lines === [] ? null : ['lines' => array_slice($lines, 0, 4), 'box' => $box];
    }
}
