<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Masks credentials and personal data in log context (SECURITY.md §2). Applied to every channel via `tap`.
 * Keys are matched case-insensitively at any depth; values in the message itself are not parsed, so code
 * must never interpolate secrets into log messages.
 */
class RedactSecrets
{
    public const KEYS = [
        'password', 'password_confirmation', 'current_password', 'access_token', 'refresh_token', 'token', 'secret',
        'app_secret', 'partner_key', 'client_secret', 'sign', 'signature', 'authorization', 'cookie', 'x-xsrf-token',
        'two_factor_secret', 'recovery_code', 'code', 'phone', 'buyer_phone', 'address', 'shipping_address', 'buyer_name',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor(new RedactSecretsProcessor);
            }
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($key) && strtolower($key) === 'email' && is_string($value)) {
                $data[$key] = preg_replace('/^(.).*(@.*)$/', '$1***$2', $value);
            }
        }

        return $data;
    }
}
