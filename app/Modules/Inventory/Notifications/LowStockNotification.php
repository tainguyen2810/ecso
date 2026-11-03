<?php

namespace App\Modules\Inventory\Notifications;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{sku: string, name: string, warehouse: string, available: int, safety_stock: int}>  $items
     */
    public function __construct(public readonly Tenant $tenant, public readonly array $items, public readonly int $total) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("[{$this->tenant->name}] {$this->total} SKU sắp hết hàng")
            ->greeting('Xin chào,')
            ->line("Các SKU sau đang ở mức bằng hoặc dưới tồn an toàn tại {$this->tenant->name}:");
        foreach (array_slice($this->items, 0, 15) as $i) {
            $mail->line("• {$i['sku']} · {$i['name']} ({$i['warehouse']}): còn {$i['available']}, tồn an toàn {$i['safety_stock']}");
        }
        if ($this->total > 15) {
            $mail->line('… và '.($this->total - 15).' SKU khác.');
        }

        return $mail->action('Xem gợi ý nhập hàng', rtrim(config('app.url'), '/').'/purchasing/reorder')
            ->line('Đổi tồn an toàn ở trang Kho hàng → thẻ kho của từng SKU.');
    }
}
