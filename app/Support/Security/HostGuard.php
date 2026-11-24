<?php

namespace App\Support\Security;

/**
 * Application-side SSRF guard (section 7.3). The crawler service enforces the same rules
 * independently; this stops unsafe hosts being registered or fetched by Laravel itself.
 */
final class HostGuard
{
    private const BLOCKED_HOSTNAMES = ['localhost', 'metadata.google.internal', 'metadata'];

    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home', '.corp', '.intranet'];

    public static function isAllowedUrl(string $url, bool $resolve = true): bool
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return self::isAllowedHost($parts['host'], $resolve);
    }

    public static function isAllowedHost(string $host, bool $resolve = true): bool
    {
        $host = strtolower(trim($host, '[].'));
        if ($host === '' || in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return false;
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }
        if (! str_contains($host, '.')) {
            return false; // single-label internal names
        }
        if (! $resolve) {
            return true;
        }

        $ips = self::resolve($host);

        return $ips !== [] && collect($ips)->every(fn ($ip) => self::isPublicIp($ip));
    }

    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! self::inRanges($ip, [
                '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '0.0.0.0/8',
            ]);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    private static function inRanges(string $ip, array $cidrs): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if ((ip2long($ip) & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
