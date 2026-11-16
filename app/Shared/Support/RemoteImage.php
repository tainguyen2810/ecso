<?php

namespace App\Shared\Support;

use Illuminate\Support\Facades\Http;

/**
 * Downloads a picture from a link a user supplied (an imported file), without letting the link reach the server's
 * own network: only http(s) on the default ports, the host must resolve to public addresses only, the connection is
 * pinned to the address that was checked, redirects are followed by hand and checked the same way, and the body
 * must be a JPEG, PNG, WebP or GIF of at most 4 MB.
 */
class RemoteImage
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Tests replace DNS lookups: host => list of IPs. */
    public static ?\Closure $resolver = null;

    /** @return array{body: string, extension: string}|null */
    public function fetch(string $url): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->check($url);
            if ($target === null) {
                return null;
            }
            [$host, $port, $ip] = $target;

            try {
                $response = Http::timeout(20)->connectTimeout(5)
                    ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                    ->withHeaders(['Accept' => 'image/*'])
                    ->get($url);
            } catch (\Throwable) {
                return null;
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    return null;
                }
                $url = str_starts_with($location, '/') && ! str_starts_with($location, '//')
                    ? parse_url($url, PHP_URL_SCHEME).'://'.$host.$location
                    : (str_starts_with($location, '//') ? 'https:'.$location : $location);

                continue;
            }
            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                return null;
            }
            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            if (! isset(self::TYPES[$type])) {
                return null;
            }

            return ['body' => $body, 'extension' => self::TYPES[$type]];
        }

        return null;
    }

    /** @return array{0: string, 1: int, 2: string}|null host, port, a checked public IP */
    private function check(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && (int) $parts['port'] !== $port) {
            return null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (self::$resolver ?? fn (string $h) => gethostbynamel($h) ?: [])($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return [$host, $port, $ips[0]];
    }
}
