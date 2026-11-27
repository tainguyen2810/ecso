<?php

namespace App\Modules\Sales\Services;

use App\Http\Middleware\SecurityHeaders;
use App\Modules\Assistant\Services\EzChatAi;
use App\Modules\Identity\Models\User;
use App\Modules\Marketplace\Connectors\EzChat\EzChatGateway;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Tin nhắn": the EzChat inbox (Zalo, Messenger, website chat…) inside this app. EzChat gives a one-time ticket for
 * the person signed in here, as long as they are a member of the joined workspace with the same EzChat account
 * ("Đăng nhập bằng EzChat") or the same email; the page frames EzChat's /erp with it. The menu badge reads the
 * member's unread chats the same way. Calls are signed with EZCHAT_SECRET (POST /api/banhang/gw/inbox/*).
 */
class EzChatInbox
{
    /** How long the badge count is kept before asking EzChat again. */
    private const UNREAD_TTL = 20;

    public function __construct(private readonly EzChatAi $workspaces) {}

    /** Why the inbox cannot open for this business, or null when it can. */
    public function unavailable(Tenant $tenant): ?string
    {
        return $this->workspaces->unavailable($tenant);
    }

    /**
     * The address of EzChat's inbox page for the frame (ticket in the fragment, never logged).
     *
     * @return array{url: string}
     */
    public function open(Tenant $tenant, User $user, string $origin, ?string $conversation = null): array
    {
        $data = $this->post('/api/banhang/gw/inbox/ticket', $this->who($tenant, $user) + ['origin' => $origin]);
        $base = rtrim((string) ($data['appUrl'] ?? config('marketplaces.ezchat.url')), '/');
        // The page's CSP (SecurityHeaders) lets this address be framed; EzChat's app may live apart from its API.
        $appOrigin = SecurityHeaders::origin($base);
        if ($appOrigin !== null && Cache::get(SecurityHeaders::APP_ORIGIN_KEY) !== $appOrigin) {
            Cache::forever(SecurityHeaders::APP_ORIGIN_KEY, $appOrigin);
        }
        $fragment = ['ticket' => (string) ($data['ticket'] ?? ''), 'lang' => 'vi'];
        if ($conversation !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $conversation) === 1) {
            $fragment['to'] = '/inbox?c='.$conversation;
        }

        return ['url' => $base.'/erp?'.http_build_query(['o' => $origin]).'#'.http_build_query($fragment)];
    }

    /** Chats with unread messages this member can see in EzChat; null when EzChat cannot be asked. */
    public function unread(Tenant $tenant, User $user): ?int
    {
        if ($this->unavailable($tenant) !== null) {
            return null;
        }

        return Cache::remember("ezchat-inbox:unread:{$tenant->id}:{$user->id}", self::UNREAD_TTL, function () use ($tenant, $user) {
            try {
                return (int) ($this->post('/api/banhang/gw/inbox/unread', $this->who($tenant, $user))['conversations'] ?? 0);
            } catch (EzChatInboxException) {
                return null;
            }
        });
    }

    /** @return array{workspaceId: string, userId: string|null, email: string} */
    private function who(Tenant $tenant, User $user): array
    {
        $workspace = $this->workspaces->workspace($tenant);
        if ($workspace === null || ! EzChatGateway::configured()) {
            throw new EzChatInboxException('Chưa nối với EzChat. Vào Kênh bán riêng › Nối với EzChat, hoặc đăng nhập bằng EzChat.', 'not_linked');
        }

        return ['workspaceId' => $workspace, 'userId' => filled($user->ezchat_user_id) ? (string) $user->ezchat_user_id : null, 'email' => (string) $user->email];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ts = (string) time();
        try {
            $response = Http::withHeaders([
                'X-Banhang-Timestamp' => $ts,
                'X-Banhang-Signature' => hash_hmac('sha256', "{$ts}.{$path}.{$raw}", (string) config('marketplaces.ezchat.secret')),
            ])->acceptJson()->timeout(15)->withUserAgent('ECSO/1.0 (+EzChat inbox)')
                ->withBody($raw, 'application/json')->post(rtrim((string) config('marketplaces.ezchat.url'), '/').$path);
        } catch (ConnectionException) {
            throw new EzChatInboxException('Không kết nối được EzChat, thử lại sau ít phút.', 'network');
        }
        $json = (array) $response->json();
        if ($response->successful()) {
            return (array) ($json['data'] ?? $json);
        }
        $code = (string) ($json['error']['code'] ?? '');
        throw new EzChatInboxException(match ($code) {
            'MEMBER_NOT_FOUND' => 'Tài khoản của bạn chưa là thành viên workspace EzChat đã nối. Nhờ chủ shop mời email này vào EzChat, hoặc đăng nhập bằng EzChat.',
            'WORKSPACE_NOT_FOUND' => 'Không tìm thấy workspace EzChat đã nối. Hãy nối lại.',
            'BANHANG_ORIGIN' => 'EzChat chưa nhận địa chỉ này là phần mềm bán hàng (BANHANG_URL trong EzChat).',
            default => $response->status() === 429 ? 'Đang mở quá nhiều, thử lại sau một phút.' : 'EzChat đang bận, thử lại sau ít phút.',
        }, $code === 'MEMBER_NOT_FOUND' ? 'not_member' : ($code ?: 'error'));
    }
}
