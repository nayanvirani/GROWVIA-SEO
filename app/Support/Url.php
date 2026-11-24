<?php

namespace App\Support;

use InvalidArgumentException;

/** URL normalization shared by website records, crawl ingestion and link matching. */
final class Url
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) && ! in_array([$scheme, (int) $parts['port']], [['http', 80], ['https', 443]], true)
            ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : preg_replace('#/{2,}#', '/', $path);
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url(self::normalize($url), PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    public static function path(string $url): string
    {
        $p = parse_url($url);

        return ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    public static function sameSite(string $url, string $host): bool
    {
        try {
            return self::host($url) === $host;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
