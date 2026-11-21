<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Marketplace\Connectors\EzChat\EzChatGateway;
use App\Modules\Sales\Models\EzChatLink;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * "AI trong Bán hàng": pictures are read by EzChat (POST /api/banhang/gw/ai, signed with EZCHAT_SECRET) and
 * paid with the EzChat AI credit of the workspace this business is joined to: the one it signed in with
 * ("Đăng nhập bằng EzChat") or the one linked in Kênh bán riêng › Nối với EzChat.
 */
class EzChatAi
{
    /** Why AI cannot run for this business, or null when it can. */
    public function unavailable(Tenant $tenant): ?string
    {
        if (! EzChatGateway::configured()) {
            return 'not_configured';
        }

        return $this->workspace($tenant) === null ? 'not_linked' : null;
    }

    public function workspace(Tenant $tenant): ?string
    {
        if (filled($tenant->ezchat_workspace_id)) {
            return (string) $tenant->ezchat_workspace_id;
        }
        $id = EzChatLink::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('workspace_id');

        return filled($id) ? (string) $id : null;
    }

    /**
     * @param  list<string>  $images  base64 JPEG/PNG, already made smaller by the browser
     * @param  array<string, mixed>  $extra
     * @return array{data: array<string, mixed>, credits: int}
     */
    public function read(Tenant $tenant, string $task, array $images, array $extra = []): array
    {
        $workspace = $this->workspace($tenant);
        if ($workspace === null || ! EzChatGateway::configured()) {
            throw new AssistantException('Chưa nối với EzChat nên chưa dùng được AI. Vào Kênh bán riêng › Nối với EzChat, hoặc đăng nhập bằng EzChat.', 'not_linked');
        }
        $path = '/api/banhang/gw/ai';
        $raw = json_encode(['workspaceId' => $workspace, 'task' => $task, 'images' => array_map(fn ($d) => ['data' => $d], $images)] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ts = (string) time();
        try {
            $response = Http::withHeaders([
                'X-Banhang-Timestamp' => $ts,
                'X-Banhang-Signature' => hash_hmac('sha256', "{$ts}.{$path}.{$raw}", (string) config('marketplaces.ezchat.secret')),
            ])->acceptJson()->timeout(120)->withUserAgent('ECSO/1.0 (+EzChat AI)')
                ->withBody($raw, 'application/json')->post(rtrim((string) config('marketplaces.ezchat.url'), '/').$path);
        } catch (ConnectionException $e) {
            throw new AssistantException('Không kết nối được EzChat, thử lại sau ít phút.', 'network');
        }
        $json = (array) $response->json();
        if ($response->successful()) {
            $body = (array) ($json['data'] ?? $json);

            return ['data' => (array) ($body['data'] ?? []), 'credits' => (int) ($body['credits'] ?? 0)];
        }
        $code = (string) ($json['error']['code'] ?? '');
        throw new AssistantException(match ($code) {
            'AI_CREDIT_OUT' => 'Workspace EzChat đã hết credit EzChat AI. Mua thêm credit trong EzChat › Gói bổ sung.',
            'FEATURE_NOT_IN_PLAN' => 'Gói EzChat hiện tại chưa có AI đọc ảnh.',
            'AI_SKILL_OFF', 'AI_SKILL_PAUSED' => 'Kỹ năng "Đọc ảnh khách gửi" đang tắt trong EzChat › Trợ lý AI. Bật lên để dùng.',
            'WORKSPACE_NOT_FOUND' => 'Không tìm thấy workspace EzChat đã nối. Hãy nối lại.',
            'IMAGE_UNREADABLE' => 'Ảnh không đọc được, chụp lại rõ hơn giúp mình.',
            'AI_UNREADABLE' => 'AI chưa đọc được nội dung ảnh. Chụp thẳng, đủ sáng, không bị mờ rồi thử lại.',
            default => $response->status() === 429 ? 'Đang gửi quá nhiều, thử lại sau một phút.' : 'AI đang bận, thử lại sau ít phút.',
        }, $code ?: 'error');
    }
}
