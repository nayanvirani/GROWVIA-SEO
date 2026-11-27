<?php

namespace App\Support\Security;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;

/**
 * Fetch a single public web page from Laravel with the same SSRF rules as the crawler:
 * public hosts only (DNS checked on every hop), manual redirects, size and time limits.
 */
final class SafeFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @return array{status: ?int, final_url: string, html: ?string, error: ?string} */
    public static function get(string $url, int $maxRedirects = 3): array
    {
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            if (! HostGuard::isAllowedUrl($url, resolve: ! app()->runningUnitTests())) {
                return ['status' => null, 'final_url' => $url, 'html' => null, 'body' => null, 'error' => 'Blocked: not a public website'];
            }
            try {
                $response = Http::timeout(15)->connectTimeout(8)
                    ->withUserAgent(config('seo.crawler.user_agent'))
                    ->withOptions(['allow_redirects' => false, 'on_headers' => function ($r) {
                        if ((int) $r->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new \RuntimeException('Page too large');
                        }
                    }])->get($url);
            } catch (\Throwable $e) {
                return ['status' => null, 'final_url' => $url, 'html' => null, 'body' => null, 'error' => mb_substr($e->getMessage(), 0, 200)];
            }
            if ($response->redirect() && $location = $response->header('Location')) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            $type = strtolower($response->header('Content-Type'));
            $isHtml = str_contains($type, 'html');
            $isText = $isHtml || str_starts_with($type, 'text/') || str_contains($type, 'xml') || str_contains($type, 'json');

            return [
                'status' => $response->status(),
                'final_url' => $url,
                'html' => $isHtml ? mb_substr($response->body(), 0, self::MAX_BYTES) : null,
                'body' => $isText ? mb_substr($response->body(), 0, self::MAX_BYTES) : null,
                'error' => $isHtml ? null : 'Not an HTML page',
            ];
        }

        return ['status' => null, 'final_url' => $url, 'html' => null, 'body' => null, 'error' => 'Too many redirects'];
    }
}
