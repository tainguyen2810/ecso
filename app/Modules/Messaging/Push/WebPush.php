<?php

namespace App\Modules\Messaging\Push;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push without a payload (RFC 8030 + VAPID, RFC 8292): the push service only wakes the service worker, which then
 * reads the message itself. No payload means no message encryption, so nothing beyond OpenSSL is needed.
 *
 * The VAPID key pair comes from VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (PEM) or is made once and kept in
 * storage/app/vapid.json, so a fresh install needs no setup step.
 */
class WebPush
{
    /** @var array{public: string, private: string}|null */
    private ?array $keys = null;

    /** The application server key the browser subscribes with (65-byte P-256 point, base64url). */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /**
     * Wakes one subscription. Returns false when the push service says it is gone (the caller forgets it).
     */
    public function wake(string $endpoint, int $ttl = 3600): bool
    {
        $response = Http::withHeaders([
            'TTL' => (string) $ttl,
            'Urgency' => 'high',
            'Authorization' => 'vapid t='.$this->jwt($endpoint).', k='.$this->publicKey(),
        ])->timeout(10)->withBody('', 'application/octet-stream')->post($endpoint);

        if (in_array($response->status(), [404, 410], true)) {
            return false;
        }
        if ($response->failed()) {
            throw new RuntimeException('Push service answered HTTP '.$response->status());
        }

        return true;
    }

    private function jwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $sub = 'mailto:'.((string) config('mail.from.address') ?: 'hello@ezchat.vn');
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']) ?: '').'.'
            .self::b64(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]) ?: '');
        $key = openssl_pkey_get_private($this->keys()['private']);
        if ($key === false || ! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign the VAPID token');
        }

        return $input.'.'.self::b64(self::derToRaw($der));
    }

    /** ECDSA signature from DER (SEQUENCE of two INTEGERs) to the 64-byte r||s JWS wants. */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $out .= str_pad(ltrim($int, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    /** @return array{public: string, private: string} */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $private = (string) config('services.vapid.private_key');
        if ($private === '') {
            $path = storage_path('app/vapid.json');
            $saved = File::exists($path) ? json_decode((string) File::get($path), true) : null;
            if (! is_array($saved) || empty($saved['private'])) {
                $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                if ($key === false || ! openssl_pkey_export($key, $pem)) {
                    throw new RuntimeException('Cannot make a VAPID key');
                }
                $saved = ['private' => $pem];
                File::put($path, (string) json_encode($saved));
                @chmod($path, 0600);
            }
            $private = (string) $saved['private'];
        }
        $key = openssl_pkey_get_private($private);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('VAPID private key is not a P-256 key');
        }
        $point = "\x04".str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return $this->keys = ['public' => self::b64($point), 'private' => $private];
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
