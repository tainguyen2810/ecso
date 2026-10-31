<?php

namespace App\Modules\Tenancy\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant, public readonly User $inviter, public readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/invitations/'.$this->token;

        return (new MailMessage)
            ->subject("Lời mời tham gia {$this->tenant->name} trên ".config('app.name'))
            ->greeting('Xin chào,')
            ->line("{$this->inviter->name} mời bạn tham gia doanh nghiệp {$this->tenant->name}.")
            ->action('Xem lời mời', $url)
            ->line('Lời mời có hiệu lực trong 7 ngày.');
    }
}
