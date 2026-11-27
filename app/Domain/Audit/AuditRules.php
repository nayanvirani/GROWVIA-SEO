<?php

namespace App\Domain\Audit;

/**
 * Rule catalogue from section 8. "heuristic" marks findings that are review prompts,
 * not strict search-engine requirements; the UI labels them accordingly.
 */
final class AuditRules
{
    public const RULES = [
        'missing_title' => [
            'severity' => 'high', 'heuristic' => false, 'label' => 'Missing page title',
            'why' => 'The title is the main label search engines and browser tabs use for the page. Without it, search engines guess.',
            'recommendation' => "Write a unique, descriptive title matching the page's intent.",
        ],
        'duplicate_title' => [
            'severity' => 'medium', 'heuristic' => false, 'label' => 'Duplicate title',
            'why' => 'When several pages share a title, it is harder for search engines and searchers to tell them apart.',
            'recommendation' => "Differentiate titles based on each page's purpose.",
        ],
        'title_length' => [
            'severity' => 'low', 'heuristic' => true, 'label' => 'Unusually short or long title',
            'why' => 'Very long titles are often truncated in results; very short ones may not describe the page well.',
            'recommendation' => 'Review for clarity and likely search-result truncation; this is not a strict Google limit.',
        ],
        'missing_meta_description' => [
            'severity' => 'medium', 'heuristic' => false, 'label' => 'Missing meta description',
            'why' => 'Search engines may choose a snippet from page content instead of a summary you wrote.',
            'recommendation' => 'Write a useful page-specific summary; Google may still rewrite snippets.',
        ],
        'duplicate_meta_description' => [
            'severity' => 'low', 'heuristic' => false, 'label' => 'Duplicate meta description',
            'why' => 'Repeated descriptions do not help searchers understand what is different about each page.',
            'recommendation' => 'Make descriptions specific to each page.',
        ],
        'meta_description_length' => [
            'severity' => 'low', 'heuristic' => true, 'label' => 'Unusually short or long meta description',
            'why' => 'Very short descriptions say little; long ones are usually cut off in results.',
            'recommendation' => 'Review the description so its key message appears early. This is a heuristic, not a rule.',
        ],
        'missing_h1' => [
            'severity' => 'medium', 'heuristic' => false, 'label' => 'Missing H1 heading',
            'why' => 'A clear main heading helps visitors and search engines understand the page topic quickly.',
            'recommendation' => 'Add a clear primary heading.',
        ],
        'multiple_h1' => [
            'severity' => 'low', 'heuristic' => true, 'label' => 'Multiple H1 headings',
            'why' => 'Several H1s can make the heading structure less clear. It is not automatically a Google penalty.',
            'recommendation' => 'Review heading hierarchy; multiple H1s are not automatically a Google penalty.',
        ],
        'missing_canonical' => [
            'severity' => 'medium', 'heuristic' => false, 'label' => 'No canonical tag',
            'why' => 'A canonical tag tells search engines which URL is the preferred version when duplicates exist.',
            'recommendation' => 'Review whether a canonical is needed and ensure it points to the preferred URL.',
        ],
        'canonical_mismatch' => [
            'severity' => 'high', 'heuristic' => false, 'label' => 'Canonical points elsewhere',
            'why' => 'If the canonical points to another URL or site, search engines may index that URL instead of this one.',
            'recommendation' => 'Investigate before changing; this may be intentional in some cases.',
        ],
        'noindex_page' => [
            'severity' => 'high', 'heuristic' => false, 'label' => 'Page blocked from indexing',
            'why' => 'A noindex directive asks search engines not to show this page in results.',
            'recommendation' => 'Confirm whether the page should be excluded from search.',
        ],
        'broken_internal_link' => [
            'severity' => 'high', 'heuristic' => false, 'label' => 'Broken internal link',
            'why' => 'Links to missing pages frustrate visitors and waste crawl effort.',
            'recommendation' => 'Fix the destination or update/remove the link.',
        ],
        'redirect_chain' => [
            'severity' => 'low', 'heuristic' => false, 'label' => 'Internal link goes through a redirect chain',
            'why' => 'Each redirect hop slows visitors down and adds work for crawlers.',
            'recommendation' => 'Update the source link to the final destination where appropriate.',
        ],
        'missing_image_alt' => [
            'severity' => 'low', 'heuristic' => false, 'label' => 'Images without alt attribute',
            'why' => 'Alt text describes images for screen readers and image search. Empty alt is fine for decorative images.',
            'recommendation' => 'Add meaningful alt for informative images; empty alt is valid for decorative images.',
        ],
        'thin_content_signal' => [
            'severity' => 'info', 'heuristic' => true, 'label' => 'Low amount of text',
            'why' => 'Very little text can mean the page does not fully answer a visitor\'s question. Word count alone does not establish quality.',
            'recommendation' => 'Review manually; word count alone does not establish quality.',
        ],
        'http_error' => [
            'severity' => 'high', 'heuristic' => false, 'label' => 'Page returns an error',
            'why' => 'Pages that return 4xx/5xx errors cannot be shown to visitors or indexed.',
            'recommendation' => 'Check whether the URL is intentionally unavailable.',
        ],
        'ai_search_blocked' => [
            'severity' => 'low', 'heuristic' => true, 'label' => 'AI search assistants are blocked',
            'why' => 'Shoppers increasingly ask AI assistants (ChatGPT search, Perplexity and others) which app to use. If robots.txt blocks their search bots, they cannot read or recommend your pages.',
            'recommendation' => 'Allow the AI search/answer bots listed in the evidence. Bots used only for AI training can stay blocked. This is a business choice, not a ranking requirement.',
        ],
        'missing_sitemap' => [
            'severity' => 'low', 'heuristic' => false, 'label' => 'No XML sitemap found',
            'why' => 'A sitemap helps search engines discover all important pages, especially new ones.',
            'recommendation' => 'Publish an XML sitemap and reference it in robots.txt.',
        ],
    ];

    public static function get(string $key): array
    {
        return self::RULES[$key] + ['key' => $key];
    }
}
