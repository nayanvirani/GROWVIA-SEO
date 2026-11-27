<?php

namespace App\Domain\Backlink;

use App\Models\BacklinkProspect;
use App\Support\Security\SafeFetcher;
use App\Support\Url;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Finds where to reach the people behind a prospect page: a "write for us / submit / contact"
 * page and any public email on the page. Paid "advertise / sponsored" pages are ignored.
 */
class ContactFinder
{
    private const PATTERNS = [
        'submit' => '/(write[- ]?for[- ]?us|contribute|guest[- ]?post(ing)?[- ]?guidelines|submit[- ]?(a[- ]?)?(tool|app|product|software|startup)|suggest[- ]?(a[- ]?)?(tool|app|product))/i',
        'contact' => '/(contact|get[- ]?in[- ]?touch|reach[- ]?us|talk[- ]?to[- ]?us)/i',
    ];

    private const IGNORE = '/(advertis|sponsor|media[- ]?kit|careers|jobs|support\.|help\.|login|signup|sign-up|pricing)/i';

    public function find(BacklinkProspect $prospect, ?string $html = null): array
    {
        $html ??= SafeFetcher::get($prospect->source_url)['html'];
        $found = $html ? $this->scan($html, $prospect->source_url) : ['url' => null, 'kind' => null, 'emails' => []];

        if (! $found['url']) { // fall back to the site's homepage navigation
            $home = 'https://'.$prospect->source_domain.'/';
            $homeHtml = SafeFetcher::get($home)['html'];
            if ($homeHtml) {
                $fromHome = $this->scan($homeHtml, $home);
                $found['url'] = $fromHome['url'];
                $found['kind'] = $fromHome['kind'];
                $found['emails'] = array_values(array_unique([...$found['emails'], ...$fromHome['emails']]));
            }
        }

        $prospect->update(['evidence_json' => array_merge($prospect->evidence_json ?? [], [
            'contact_url' => $found['url'],
            'contact_kind' => $found['kind'],
            'contact_emails' => array_slice($found['emails'], 0, 3),
            'contact_checked_at' => now()->toDateString(),
        ])]);

        return $found;
    }

    /** @return array{url: ?string, kind: ?string, emails: list<string>} */
    public function scan(string $html, string $pageUrl): array
    {
        preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER);
        $pageHost = rescue(fn () => Url::host($pageUrl), '', false);
        $best = ['submit' => null, 'contact' => null];
        $emails = [];
        foreach ($m as [$_, $href, $text]) {
            $href = html_entity_decode(trim($href));
            $text = trim(strip_tags($text));
            if (stripos($href, 'mailto:') === 0) {
                $email = strtolower(trim(explode('?', substr($href, 7))[0]));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! preg_match('/(noreply|no-reply|privacy|legal|abuse|dpo)/', $email)) {
                    $emails[] = $email;
                }

                continue;
            }
            $abs = rescue(fn () => (string) UriResolver::resolve(new Uri($pageUrl), new Uri($href)), null, false);
            if (! $abs || ! preg_match('#^https?://#', $abs) || rescue(fn () => Url::host($abs), null, false) !== $pageHost) {
                continue;
            }
            $hay = $href.' '.$text;
            if (preg_match(self::IGNORE, $hay)) {
                continue;
            }
            foreach (self::PATTERNS as $kind => $re) {
                if (! $best[$kind] && preg_match($re, $hay)) {
                    $best[$kind] = $abs;
                }
            }
        }

        $kind = $best['submit'] ? 'submit' : ($best['contact'] ? 'contact' : null);

        return ['url' => $kind ? $best[$kind] : null, 'kind' => $kind, 'emails' => array_values(array_unique($emails))];
    }
}
