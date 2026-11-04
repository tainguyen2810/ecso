<?php

namespace App\Modules\Marketplace\Notifications;

use App\Modules\Marketplace\Models\FeeAlert;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Daily fee check: a marketplace charging differently from the fee table, or orders sold at a loss after fees. */
class FeeAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<FeeAlert>  $alerts */
    public function __construct(public readonly Tenant $tenant, public readonly array $alerts) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("[{$this->tenant->name}] Kiểm tra phí sàn: ".count($this->alerts).' cảnh báo')->greeting('Xin chào,');
        foreach ($this->alerts as $alert) {
            $mail->line('• '.self::describe($alert));
        }

        return $mail->action('Mở Kiểm tra phí sàn', rtrim(config('app.url'), '/').'/settings/pricing/fees');
    }

    public static function describe(FeeAlert $alert): string
    {
        $shop = $alert->account->shop_name ?? 'Gian hàng';
        $d = $alert->data;
        if ($alert->type === FeeAlert::FEE_DRIFT) {
            $text = sprintf('%s: phí thực tế %s%% (%d ngày, %d đơn), bảng phí đang cài %s%%.', $shop,
                self::pct($d['actual']['total_pct']), $d['days'], $d['actual']['orders'], self::pct($d['table_total']));

            return ! empty($d['auto_updated']) ? $text.' ECSO đã tự cập nhật bảng phí và tính lại giá sàn.' : $text;
        }

        return sprintf('%s: %d đơn bán lỗ sau phí trong %d ngày, tổng lỗ %s đ.', $shop, $d['count'], $d['days'], number_format((float) $d['total_loss'], 0, ',', '.'));
    }

    private static function pct(float|int $value): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));
    }
}
