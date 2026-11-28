<?php

namespace App\Domain\Keyword;

use App\Domain\AI\AiService;
use App\Models\Keyword;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Support\Str;

/**
 * Writes the ready-to-paste content for a keyword action and says exactly where it goes:
 *  - "section": a new H2 section (heading, paragraphs, optional FAQ) placed after/before real headings;
 *  - "title":   a new SEO title and meta description for the page.
 */
class KeywordContentWriter
{
    /** Headings that usually close a page; new sections go before them. */
    private const CLOSING = '/\b(faq|questions|conclusion|final|wrap|summary|ready to|get started|start (free|now|today)|try|pricing|plans|next steps|related|more articles|contact|works (well )?with|integrations?)\b/i';

    /** "1. Test one idea", "Step 2: ..." — part of a sequence that must not be split. */
    private const NUMBERED = '/^\s*(\d+[.):]|step \d+)/i';

    public function __construct(private readonly AiService $ai) {}

    /**
     * Where a new section about $keyword belongs among the page's existing H2s, in reading order.
     *
     * @return array{after: ?string, before: ?string, text: string}
     */
    public function placement(WebsitePage $page, string $keyword, ?string $context): array
    {
        $h2 = array_values(array_filter(array_map('trim', $page->headings_json['h2'] ?? [])));
        if (! $h2) {
            return ['after' => null, 'before' => null, 'text' => 'Add it near the end of the main text, just before the final call-to-action button.'];
        }

        // Words from the page's own title are everywhere on it ("bundle" on the bundles page): ignore them.
        $titleStems = array_map(fn ($w) => preg_replace('/(ies|s)$/', '', $w), SiteProfile::words((string) $page->title));
        $words = array_values(array_diff(array_map(fn ($w) => preg_replace('/(ies|s)$/', '', $w), TopicMatch::coreWords($keyword, $context)), $titleStems));
        $best = null;
        $bestScore = 0;
        foreach ($h2 as $i => $heading) {
            $text = Str::lower($heading);
            $score = count(array_filter($words, fn ($w) => str_contains($text, $w)));
            if ($score > $bestScore && ! preg_match(self::CLOSING, $heading)) {
                [$best, $bestScore] = [$i, $score];
            }
        }
        // Never split a numbered sequence: move to after its last step.
        if ($best !== null && preg_match(self::NUMBERED, $h2[$best])) {
            while (isset($h2[$best + 1]) && preg_match(self::NUMBERED, $h2[$best + 1])) {
                $best++;
            }
        }

        if ($best !== null) {
            $before = $h2[$best + 1] ?? null;

            return ['after' => $h2[$best], 'before' => $before,
                'text' => "Put it right after the section \"{$h2[$best]}\"".($before ? " and before \"{$before}\"." : ', at the end of that part of the page.')];
        }

        foreach ($h2 as $i => $heading) {
            if (preg_match(self::CLOSING, $heading)) {
                $after = $h2[$i - 1] ?? null;

                return ['after' => $after, 'before' => $heading,
                    'text' => ($after ? "Put it after the section \"{$after}\" and " : 'Put it ')."before \"{$heading}\" (keep the closing sections last)."];
            }
        }
        $last = end($h2);

        return ['after' => $last, 'before' => null, 'text' => "Put it after the last section, \"{$last}\", before the page's final call-to-action."];
    }

    public function write(Website $website, Keyword $keyword, WebsitePage $page, string $kind): array
    {
        $context = SiteProfile::for($website)->context;
        $placement = $kind === 'section' ? $this->placement($page, $keyword->keyword, $context) : null;
        $facts = [
            'page_url' => $page->canonical_url,
            'page_title' => $page->title,
            'page_headings_in_order' => array_slice(array_merge($page->headings_json['h1'] ?? [], $page->headings_json['h2'] ?? []), 0, 20),
            'page_content_excerpt_untrusted' => Str::limit((string) $page->text_excerpt, 3000),
            'target_search_phrase' => $keyword->keyword,
            'target_keyword' => $keyword->keyword,
            'searcher_intent' => $keyword->intent,
        ];

        if ($kind === 'title') {
            $result = $this->ai->run($website, 'title_write', $facts + [
                'instructions' => 'Write a new SEO title (max 60 characters, include the search phrase naturally, end with " | '.$website->name.'") '
                    .'and a meta description (120-155 characters, plain benefit + call to action). Only true facts from the page. '
                    .'Return {"title":"","meta_description":""}.',
            ]);

            return ['kind' => 'title', 'title' => Str::limit(trim($result['data']['title']), 70, ''),
                'meta_description' => Str::limit(trim($result['data']['meta_description']), 170, ''), 'model' => $result['model']];
        }

        $result = $this->ai->run($website, 'section_write', $facts + [
            'section_goes' => $placement['text'],
            'instructions' => 'Write ONE new section for this existing page that answers the search phrase for a store owner. '
                .'heading: H2 text (natural, includes the phrase or a close variant). paragraphs: 2-4 short paragraphs (40-80 words each), '
                .'practical and specific, in the page\'s tone, mentioning how '.$website->name.' helps only where the page content supports it. '
                .'bullets: optional 3-5 short list items. faq: one question and a 1-2 sentence answer. '
                .'Use ONLY facts from the page and brand facts; mark anything you are unsure of with [check]. '
                .'Return {"heading":"","paragraphs":[""],"bullets":[""],"faq":{"question":"","answer":""}}.',
        ]);
        $d = $result['data'];

        return [
            'kind' => 'section',
            'heading' => trim((string) $d['heading']),
            'paragraphs' => array_values(array_filter(array_map('trim', (array) $d['paragraphs']))),
            'bullets' => array_values(array_filter(array_map('trim', (array) ($d['bullets'] ?? [])))),
            'faq' => ! empty($d['faq']['question']) ? ['question' => trim($d['faq']['question']), 'answer' => trim((string) ($d['faq']['answer'] ?? ''))] : null,
            'placement' => $placement,
            'model' => $result['model'],
        ];
    }
}
