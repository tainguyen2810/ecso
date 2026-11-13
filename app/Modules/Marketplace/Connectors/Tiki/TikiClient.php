<?php

namespace App\Modules\Marketplace\Connectors\Tiki;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tiki Open API for a shop's own In-house app (open.tiki.vn → Getting started):
 *  - token: POST https://api.tiki.vn/sc/oauth2/token, Basic client_id:client_secret, grant_type=client_credentials.
 *    No refresh token is issued: a new token is asked for the same way when the old one ends.
 *  - calls: https://api.tiki.vn/integration/v2/… with "Authorization: Bearer <token>" (e.g. GET sellers/me).
 */
class TikiClient
{
    public const TOKEN_URL = 'https://api.tiki.vn/sc/oauth2/token';

    public const BASE_URL = 'https://api.tiki.vn/integration/v2/';

    public function __construct(private readonly string $clientId, private readonly string $clientSecret, private ?string $token = null) {}

    /** A client for a connected shop, reusing its token while it lasts. */
    public static function forAccount(MarketplaceAccount $account): self
    {
        $credential = $account->credential;
        $clientId = (string) ($account->settings['client_id'] ?? '');
        if (! $credential || $clientId === '' || blank($credential->refresh_token)) {
            throw new ConnectorException('Shop Tiki chưa có Client ID / Client Secret. Vui lòng kết nối lại.', temporary: false, authExpired: true);
        }
        $live = $credential->access_expires_at && $credential->access_expires_at->isAfter(now()->addMinute());

        return new self($clientId, (string) $credential->refresh_token, $live ? (string) $credential->access_token : null);
    }

    /**
     * @return array{access_token: string, expires_at: CarbonImmutable|null}
     *
     * @throws ConnectorException
     */
    public function requestToken(): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->withBasicAuth($this->clientId, $this->clientSecret)
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials', 'client_id' => $this->clientId]);
        } catch (ConnectionException) {
            throw new ConnectorException('Không kết nối được Tiki, thử lại sau.');
        }
        $token = (string) $response->json('access_token', '');
        if (! $response->successful() || $token === '') {
            $bad = in_array($response->status(), [400, 401, 403], true);
            throw new ConnectorException($bad ? 'Client ID hoặc Client Secret không đúng, hoặc ứng dụng chưa được kết nối với shop trên Tiki.' : 'Tiki báo lỗi '.$response->status().' khi cấp token.', temporary: ! $bad, authExpired: $bad);
        }
        $this->token = $token;
        $seconds = (int) $response->json('expires_in', 0);

        return ['access_token' => $token, 'expires_at' => $seconds > 0 ? CarbonImmutable::now()->addSeconds($seconds) : null];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectorException
     */
    public function get(string $path, array $query = []): array
    {
        if ($this->token === null) {
            $this->requestToken();
        }
        $response = $this->send($path, $query);
        if ($response->status() === 401) {
            $this->requestToken(); // the token ended early: one more try with a new one
            $response = $this->send($path, $query);
        }
        if (! $response->successful()) {
            throw new ConnectorException('Tiki báo lỗi '.$response->status().': '.mb_substr((string) ($response->json('message') ?? $response->json('error') ?? $response->body()), 0, 200), temporary: $response->serverError(), authExpired: in_array($response->status(), [401, 403], true), notFound: $response->status() === 404);
        }

        return (array) $response->json();
    }

    /** @param  array<string, mixed>  $query */
    private function send(string $path, array $query): Response
    {
        try {
            return Http::acceptJson()->timeout(20)->withToken((string) $this->token)->get(self::BASE_URL.ltrim($path, '/'), $query);
        } catch (ConnectionException) {
            throw new ConnectorException('Không kết nối được Tiki, thử lại sau.');
        }
    }
}
