<?php

namespace App\Domain\Backlink;

use Illuminate\Support\Str;

/**
 * Section 12.2 scoring model (100 points). A prioritisation aid, not a search-engine metric.
 * Dimensions without evidence score 0 and are reported as "unavailable".
 */
class ProspectScorer
{
    public const WEIGHTS = [
        'niche_relevance' => 25, 'editorial_quality' => 20, 'organic_visibility' => 15, 'credibility' => 15,
        'audience_relevance' => 10, 'freshness' => 5, 'outbound_link_quality' => 5, 'spam_risk' => 5,
    ];

    private const NICHE_TERMS = ['shopify', 'ecommerce', 'e-commerce', 'store', 'merchant', 'dtc', 'retail', 'conversion', 'upsell', 'cart', 'checkout'];

    private const AUDIENCE_TERMS = ['store owner', 'merchant', 'seller', 'founder', 'brand', 'shop'];

    private const SPAM_PATTERNS = [
        'link_farm_terms' => '/\b(cheap[- ]?links?|buy[- ]?backlinks?|seo[- ]?links?|link[- ]?(farm|exchange)|guest[- ]?post[- ]?(service|for sale)|casino|viagra|loans?\b|crypto[- ]?bonus)\b/i',
        'submission_directory' => '/\b(submit[- ]?(your)?[- ]?(site|url|link)|free[- ]?directory|add[- ]?url)\b/i',
    ];

    /** @return array{score: int, breakdown: array, risk_flags: list<string>, hard_reject: bool} */
    public function score(array $p): array
    {
        $text = Str::lower(($p['page_title'] ?? '').' '.($p['source_url'] ?? '').' '.($p['notes'] ?? ''));
        $flags = [];
        foreach (self::SPAM_PATTERNS as $flag => $pattern) {
            if (preg_match($pattern, $text)) {
                $flags[] = $flag;
            }
        }
        $host = (string) parse_url((string) ($p['source_url'] ?? ''), PHP_URL_HOST);
        if (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/\.(xyz|top|click|loan|work|biz)$/i', $host)) {
            $flags[] = 'low_trust_tld_or_ip';
        }
        if (str_contains((string) ($p['rel'] ?? ''), 'sponsored')) {
            $flags[] = 'paid_placement_needs_rel_sponsored';
        }
        foreach ((array) ($p['risk_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        $niche = collect(self::NICHE_TERMS)->filter(fn ($t) => str_contains($text, $t))->count();
        $audience = collect(self::AUDIENCE_TERMS)->filter(fn ($t) => str_contains($text, $t))->count();
        $editorial = ! empty($p['editorial_hint']) || preg_match('#/(blog|guides?|resources?|library|articles?|learn)/#i', (string) ($p['source_url'] ?? '')) ? 1 : 0;

        $competitors = (int) ($p['competitor_links'] ?? 0);
        $b = [
            'niche_relevance' => ['points' => min(25, $niche * 7 + min($competitors, 2) * 6),
                'evidence' => "{$niche} niche terms in title/URL".($competitors ? "; already links to {$competitors} competitor(s)" : '')],
            'editorial_quality' => ['points' => $editorial ? 16 : 6, 'evidence' => $editorial ? 'Editorial article, list or resource page' : 'No editorial signal'],
            'organic_visibility' => isset($p['organic_traffic'])
                ? ['points' => (int) min(15, round(log10(max((int) $p['organic_traffic'], 1)) * 3)), 'evidence' => "Provider traffic estimate: {$p['organic_traffic']}"]
                : ['points' => 0, 'evidence' => 'unavailable'],
            'credibility' => isset($p['domain_rating'])
                ? ['points' => (int) round(min(100, (int) $p['domain_rating']) / 100 * 15), 'evidence' => "Provider authority metric {$p['domain_rating']} (third-party, not a Google metric)"]
                : ['points' => 0, 'evidence' => 'unavailable'],
            'audience_relevance' => ['points' => min(10, 4 + $audience * 3), 'evidence' => "{$audience} audience terms"],
            'freshness' => isset($p['last_updated'])
                ? ['points' => now()->diffInMonths($p['last_updated']) <= 12 ? 5 : 1, 'evidence' => "Last updated {$p['last_updated']}"]
                : ['points' => 0, 'evidence' => 'unavailable'],
            'outbound_link_quality' => isset($p['outbound_links'])
                ? ['points' => (int) $p['outbound_links'] <= 100 ? 5 : 1, 'evidence' => "{$p['outbound_links']} outbound links"]
                : ['points' => 0, 'evidence' => 'unavailable'],
            'spam_risk' => ['points' => $flags ? 0 : 5, 'evidence' => $flags ? implode(', ', $flags) : 'No spam signals detected'],
        ];

        $hardReject = (bool) array_intersect($flags, ['link_farm_terms', 'malware', 'hacked', 'private_network', 'paid_link_scheme']);

        // A competitor's own blog rarely recommends rivals: keep it, but lower the priority.
        $penalty = in_array('competitor_owned', $flags, true) ? 15 : 0;

        return [
            'score' => $hardReject ? 0 : max(0, array_sum(array_column($b, 'points')) - $penalty),
            'breakdown' => $b,
            'risk_flags' => array_values(array_unique($flags)),
            'hard_reject' => $hardReject,
        ];
    }
}
