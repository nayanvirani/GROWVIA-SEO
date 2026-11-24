<?php

namespace App\Domain\Content;

use App\Models\ContentDraft;
use App\Models\WebsitePage;
use Illuminate\Support\Str;

/** Section 10.4 checks. Flags are advisory; a human reviews every draft. */
class QualityChecker
{
    public function check(ContentDraft $draft, ?string $primaryKeyword = null): array
    {
        $body = (string) $draft->body_markdown;
        $flags = [];

        if (preg_match_all('/\b\d+(\.\d+)?\s?(%|percent|x\b|times)/i', $body, $m)) {
            $flags[] = ['type' => 'unverified_statistic', 'severity' => 'high', 'message' => 'Contains statistics that must be traced to a source: '.implode(', ', array_slice(array_unique($m[0]), 0, 5))];
        }
        if (preg_match('/\b(guarantee[ds]?|#1|number one|rank (first|#1)|best in the world|100% (safe|secure))\b/i', $body, $m)) {
            $flags[] = ['type' => 'unsupported_claim', 'severity' => 'high', 'message' => "Possible unsupported claim: \"{$m[0]}\"."];
        }
        if (preg_match('/\b(better than|unlike|compared to|vs\.?)\s+[A-Z][\w]+/', $body, $m)) {
            $flags[] = ['type' => 'unverified_comparison', 'severity' => 'medium', 'message' => "Competitor comparison needs evidence: \"{$m[0]}\"."];
        }
        if (Str::contains($body, '[needs human')) {
            $flags[] = ['type' => 'placeholder', 'severity' => 'medium', 'message' => 'Draft still contains placeholders marked for human detail.'];
        }

        $words = max(str_word_count(strip_tags($body)), 1);
        if ($primaryKeyword) {
            $count = substr_count(Str::lower($body), Str::lower($primaryKeyword));
            $density = $count * str_word_count($primaryKeyword) / $words;
            if ($density > 0.04) {
                $flags[] = ['type' => 'keyword_stuffing', 'severity' => 'medium', 'message' => sprintf('Primary keyword appears %d times (%.1f%% of words). Read it aloud and reduce repetition.', $count, $density * 100)];
            } elseif ($count === 0) {
                $flags[] = ['type' => 'keyword_missing', 'severity' => 'low', 'message' => 'The primary keyword does not appear. Mention the topic naturally where it fits.'];
            }
        }

        if ($similar = $this->mostSimilarPage($draft)) {
            if ($similar['similarity'] >= 0.6) {
                $flags[] = ['type' => 'duplicate_similarity', 'severity' => 'high', 'message' => sprintf('Text is %d%% similar to %s. Make it distinct before export.', $similar['similarity'] * 100, $similar['url'])];
            }
        }

        return $flags;
    }

    /** Jaccard similarity on word 3-shingles against crawled page excerpts. */
    private function mostSimilarPage(ContentDraft $draft): ?array
    {
        $a = $this->shingles((string) $draft->body_markdown);
        if (count($a) < 10) {
            return null;
        }
        $best = null;
        WebsitePage::forWebsite($draft->website_id)->whereNotNull('text_excerpt')->select(['id', 'canonical_url', 'text_excerpt'])
            ->chunk(200, function ($pages) use ($a, &$best) {
                foreach ($pages as $p) {
                    $b = $this->shingles($p->text_excerpt);
                    if (! $b) {
                        continue;
                    }
                    $sim = count(array_intersect_key($a, $b)) / count($a + $b);
                    if ($sim > ($best['similarity'] ?? 0)) {
                        $best = ['url' => $p->canonical_url, 'similarity' => round($sim, 2)];
                    }
                }
            });

        return $best;
    }

    private function shingles(string $text): array
    {
        $w = preg_split('/\W+/u', Str::lower(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        for ($i = 0; $i + 2 < count($w); $i++) {
            $out[$w[$i].' '.$w[$i + 1].' '.$w[$i + 2]] = true;
        }

        return $out;
    }
}
