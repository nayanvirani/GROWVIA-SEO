<?php

namespace App\Providers\AI;

use Illuminate\Support\Str;

/**
 * Deterministic offline provider. Outputs are built only from the supplied context so the
 * whole workflow can be developed and tested without an LLM key. Text is clearly templated.
 */
class MockAiProvider implements AiContentProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function generate(string $task, array $context, array $schema): array
    {
        $data = match ($task) {
            'explain_issue' => $this->explainIssue($context),
            'meta_suggestions' => $this->metaSuggestions($context),
            'content_brief' => $this->contentBrief($context),
            'blog_draft', 'landing_page' => $this->draft($context),
            'faq' => ['faqs' => $this->faqs($context)],
            'image_brief' => $this->imageBrief($context),
            'outreach_email' => $this->outreach($context),
            'classify_intent' => $this->classify($context),
            'seed_topics' => $this->seedTopics($context),
            'listing_pack' => [],
            'page_update' => ['summary' => 'Apply the change described above.'],
            'section_write' => [
                'heading' => Str::ucfirst($this->kw($context)),
                'paragraphs' => ['[Offline demo text] Explain '.$this->kw($context).' in plain words for a store owner. [check]',
                    '[Offline demo text] Describe how '.$this->site($context).' helps, using only facts from your page. [check]'],
                'bullets' => [],
                'faq' => ['question' => 'What is '.$this->kw($context).'?', 'answer' => '[Offline demo text] Short, factual answer. [check]'],
            ],
            'title_write' => ['title' => Str::limit(Str::title($this->kw($context)).' | '.$this->site($context), 60, ''), 'meta_description' => 'Learn about '.$this->kw($context).' with '.$this->site($context).'. [Offline demo text]'],
            default => [],
        };

        return ['data' => $data, 'model' => 'mock-deterministic', 'input_tokens' => 0, 'output_tokens' => 0];
    }

    private function kw(array $c): string
    {
        return trim((string) ($c['target_keyword'] ?? $c['title'] ?? 'your topic')) ?: 'your topic';
    }

    private function site(array $c): string
    {
        return (string) ($c['website_name'] ?? 'the website');
    }

    private function explainIssue(array $c): array
    {
        return [
            'explanation' => 'This was detected directly in the latest crawl: '.($c['issue_summary'] ?? 'see the evidence').
                ' Fixing it helps search engines and visitors understand the page. Check the evidence before changing anything on the live site.',
            'next_step' => $c['recommendation'] ?? 'Review the page and apply the suggested fix.',
            'confidence' => 'high',
        ];
    }

    private function metaSuggestions(array $c): array
    {
        $topic = Str::limit($c['page_title'] ?: $this->kw($c), 45, '');
        $site = $this->site($c);

        return ['variants' => [
            ['meta_title' => "{$topic} | {$site}", 'meta_description' => Str::limit("Learn how {$site} helps with ".Str::lower($topic).'. See features, how it works and how to get started.', 155)],
            ['meta_title' => Str::limit("{$topic} – {$site}", 60, ''), 'meta_description' => Str::limit('Explore '.Str::lower($topic)." with {$site}. Practical details and next steps for store owners.", 155)],
        ]];
    }

    private function contentBrief(array $c): array
    {
        $kw = $this->kw($c);

        return [
            'title' => Str::title($kw).': A Practical Guide',
            'audience' => $c['audience'] ?: 'Store owners evaluating '.$kw,
            'intent' => $c['intent'] ?: 'informational',
            'outline' => [
                ['heading' => 'What is '.$kw.'?', 'points' => ['Plain-language definition', 'Who it is for']],
                ['heading' => 'Why it matters', 'points' => ['Common problems it solves', 'When it is not the right fit']],
                ['heading' => 'How to get started', 'points' => ['Step-by-step setup', 'What to measure']],
                ['heading' => 'How '.$this->site($c).' can help', 'points' => ['Only facts from approved product notes']],
                ['heading' => 'FAQ', 'points' => ['Answer real customer questions']],
            ],
            'questions_for_human' => ['Which product facts should be cited?', 'Is there a customer example we are allowed to mention?'],
        ];
    }

    private function draft(array $c): array
    {
        $kw = $this->kw($c);
        $site = $this->site($c);
        $outline = $c['outline'] ?? $this->contentBrief($c)['outline'];
        $title = $c['title'] ?? Str::title($kw);
        $body = "# {$title}\n\n";
        $body .= "> Draft generated in mock mode for structure review. Replace placeholder text with verified facts before export.\n\n";
        foreach ($outline as $section) {
            $body .= '## '.($section['heading'] ?? 'Section')."\n\n";
            foreach ($section['points'] ?? [] as $point) {
                $body .= "- {$point}: explain this for readers interested in {$kw}. [needs human detail]\n";
            }
            $body .= "\n";
        }
        if (! empty($c['source_notes'])) {
            $body .= "## Notes from approved sources\n\n".Str::limit((string) $c['source_notes'], 1200)."\n";
        }

        return [
            'title' => $title,
            'body_markdown' => $body,
            'meta_title' => Str::limit("{$title} | {$site}", 60, ''),
            'meta_description' => Str::limit("A practical guide to {$kw} for store owners, with steps to get started.", 155, ''),
            'slug' => Str::slug($kw),
            'faqs' => $this->faqs($c),
            'claims_needing_review' => [],
        ];
    }

    private function faqs(array $c): array
    {
        $kw = $this->kw($c);

        return [
            ['question' => "What is {$kw}?", 'answer' => "[needs human answer] A short definition of {$kw} based on approved facts."],
            ['question' => "Who should use {$kw}?", 'answer' => '[needs human answer] Describe the intended audience.'],
            ['question' => "How do I get started with {$kw}?", 'answer' => '[needs human answer] List the first practical steps.'],
        ];
    }

    private function imageBrief(array $c): array
    {
        $kw = $this->kw($c);

        return [
            'brief' => "Clean editorial illustration representing {$kw} for an ecommerce audience. Use brand colours, no text, no fake product UI.",
            'prompt' => "Minimal flat illustration of an online store owner working on {$kw}, soft gradients, modern, no text, no logos, no screenshots",
            'aspect_ratio' => $c['aspect_ratio'] ?? '16:9',
            'placement' => $c['placement'] ?? 'featured image',
            'alt_text' => Str::ucfirst("Illustration of a store owner working on {$kw}"),
            'suggested_filename' => Str::slug($kw).'-illustration.webp',
        ];
    }

    private function outreach(array $c): array
    {
        $site = $this->site($c);
        $title = $c['prospect_title'] ?? $c['source_domain'] ?? 'your resource';

        if (($c['kind'] ?? null) === 'unlinked_mention') {
            return [
                'subject' => Str::limit("Thanks for mentioning {$site}", 90, ''),
                'body' => "Hi there,\n\nThank you for mentioning {$site} in \"{$title}\" — we really appreciate it.\n\n"
                    ."Would you be open to linking the mention to {$c['target_url']} so readers can find us easily? "
                    ."Totally fine if not.\n\nBest regards,\n[Your name]",
            ];
        }

        return [
            'subject' => Str::limit("A resource suggestion for {$title}", 90, ''),
            'body' => "Hi there,\n\nI came across \"{$title}\" while researching resources for ecommerce store owners. ".
                "[Personalise: mention something specific you found useful on the page.]\n\n".
                "We run {$site}. If you think it would genuinely help your readers, you may find {$c['target_url']} relevant. ".
                "No pressure at all — and if it is not a fit, thanks for reading.\n\nBest regards,\n[Your name]",
        ];
    }

    private function classify(array $c): array
    {
        return ['results' => array_map(fn ($k) => ['keyword' => $k, 'intent' => self::heuristicIntent($k)], $c['keywords'] ?? [])];
    }

    /** Rule-based intent used offline and as a fallback when an AI answer is missing. */
    public static function heuristicIntent(string $keyword): string
    {
        $l = ' '.Str::lower($keyword).' ';

        return match (true) {
            Str::contains($l, [' buy ', ' price', ' pricing', ' install', ' download', ' free trial', ' coupon', ' discount', ' sign up', ' cost']) => 'transactional',
            Str::contains($l, [' best ', ' vs ', ' review', ' alternative', ' compare', ' top ', ' comparison']) => 'commercial',
            Str::contains($l, [' login', ' sign in', ' dashboard', ' support ', ' contact ']) => 'navigational',
            Str::contains($l, [' how ', ' what ', ' why ', ' when ', ' guide', ' tips', ' ideas', ' examples', ' tutorial', ' meaning',
                ' is ', ' are ', ' does ', ' do ', ' can ', ' should ', ' ways ', ' strategy', ' strategies', ' definition', ' explained']) => 'informational',
            // A short product/category phrase ("shopify checkout upsell") is someone looking for a solution.
            default => 'commercial',
        };
    }

    private function seedTopics(array $c): array
    {
        return ['seeds' => []]; // offline: seeds come from the site's own pages
    }
}
