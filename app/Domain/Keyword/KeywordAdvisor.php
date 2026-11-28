<?php

namespace App\Domain\Keyword;

use App\Models\ContentBrief;
use App\Models\Keyword;
use App\Models\Recommendation;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns each keyword into one plain-English action: nothing to do, improve a page (where and what
 * to paste), create a post or landing page, already planned, or skip.
 */
class KeywordAdvisor
{
    public const GROUPS = [
        'todo' => 'To do',
        'planned' => 'Planned',
        'done' => 'Covered',
        'skip' => 'Not worth it',
    ];

    /**
     * @param  Collection<int, Keyword>  $keywords  loaded with pageTargets.page
     * @return array<string, array> keyword id => advice
     */
    public function advise(Website $website, Collection $keywords): array
    {
        $context = SiteProfile::for($website)->context;
        $this->competitors = $this->competitorNames($website);
        $this->core = $this->coreTopics(SiteProfile::for($website));
        $recs = Recommendation::forWebsite($website)->where('status', 'open')->whereNotNull('keyword_id')->get()->keyBy('keyword_id');
        $briefs = ContentBrief::forWebsite($website)->whereNotNull('primary_keyword_id')->withCount('drafts')->get()->keyBy('primary_keyword_id');
        $out = [];

        $this->pages = WebsitePage::forWebsite($website)->where('status_code', 200)->get(['id', 'canonical_url', 'title', 'h1']);
        $seen = [];
        foreach ($keywords->sortByDesc('opportunity_score') as $k) {
            $advice = $this->one($website, $k, $context, $recs[$k->id] ?? null, $briefs[$k->id] ?? null);
            // One action per topic: "best shopify bundle builder" and "shopify bundle builder app" need the same page.
            if ($advice['group'] === 'todo') {
                $topic = $this->topicKey($k->keyword, $context);
                if (isset($seen[$topic])) {
                    $advice = ['group' => 'skip', 'action' => 'Same topic', 'summary' => "Same topic as \"{$seen[$topic]}\". One page covers both, so follow that one.",
                        'page_url' => null, 'page_path' => null, 'recommendation_id' => null, 'suggestion' => null, 'steps' => [], 'can_brief' => false];
                } else {
                    $seen[$topic] = $k->keyword;
                }
            }
            $out[$k->id] = $advice;
        }

        return $out;
    }

    private Collection $pages;

    public function __construct(private readonly KeywordResearchService $research) {}

    private function topicKey(string $keyword, ?string $context): string
    {
        return collect(TopicMatch::coreWords($keyword, $context))
            ->reject(fn ($w) => in_array($w, ['review', 'reviews', 'tutorial', 'guide', 'example', 'examples'], true))
            ->map(fn ($w) => preg_replace('/(ies|s|er|ing)$/', '', $w))->unique()->sort()->implode(' ');
    }

    /** @var array<string, string> token => display name */
    private array $competitors = [];

    private const GENERIC_NAME = ['shopify', 'bundle', 'bundles', 'product', 'products', 'app', 'apps', 'simple', 'fast', 'free', 'easy',
        'smart', 'best', 'upsell', 'upsells', 'cart', 'gift', 'gifts', 'quantity', 'discount', 'discounts', 'store', 'sales', 'shop'];

    /** Competitor brand tokens: "apps.shopify.com/kaching-bundles" -> kaching => "Kaching Bundles". */
    private function competitorNames(Website $w): array
    {
        $names = [];
        foreach ($w->competitors()->where('active', true)->pluck('domain') as $id) {
            if (str_contains($id, '/')) {
                $slug = Str::afterLast($id, '/');
            } else {
                // support.kachingappz.com -> kachingappz -> kaching
                $labels = explode('.', $id);
                $slug = $labels[max(0, count($labels) - 2)];
                $slug = preg_replace('/(appz|apps|app|hq|labs|io)$/', '', $slug) ?: $slug;
            }
            $token = Str::lower(explode('-', $slug)[0]);
            if (mb_strlen($token) >= 4 && ! in_array($token, self::GENERIC_NAME, true)) {
                $names[$token] ??= Str::title(str_replace('-', ' ', Str::limit($slug, 30, '')));
            }
        }

        return $names;
    }

    /** @var list<string> core topic stems from the site's main titles: bundle, upsell, gift, checkout... */
    private array $core = [];

    private function coreTopics(SiteProfile $profile): array
    {
        return collect(array_slice($profile->phrases, 0, 12))
            ->flatMap(fn ($p) => SiteProfile::words($p))
            ->reject(fn ($t) => $t === $profile->context || mb_strlen($t) < 3 || in_array($t, SiteProfile::STOPWORDS, true) || in_array($t, ['bar', 'free'], true))
            ->map(fn ($t) => preg_replace('/(ies|s)$/', '', $t))->unique()->values()->all();
    }

    private function hasCoreTopic(string $keyword): bool
    {
        $words = array_map(fn ($t) => preg_replace('/(ies|s|ing)$/', '', $t), SiteProfile::words(str_replace('-', ' ', $keyword)));

        return (bool) array_intersect($words, $this->core);
    }

    private function mentionsCompetitor(string $keyword): ?string
    {
        foreach ($this->competitors as $token => $name) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/i', $keyword)) {
                return $name;
            }
        }

        return null;
    }

    private function one(Website $w, Keyword $k, ?string $context, ?Recommendation $rec, ?ContentBrief $brief): array
    {
        $relevance = $k->opportunity_json['relevance']['points'] ?? 18;
        $page = $k->pageTargets->first()?->page;
        $path = $page ? (parse_url($page->canonical_url, PHP_URL_PATH) ?: '/') : null;
        $base = ['page_url' => $page?->canonical_url, 'page_path' => $path, 'recommendation_id' => $rec?->id, 'suggestion' => null, 'steps' => [], 'can_brief' => false];

        // Already being written: say so first.
        if ($brief) {
            $state = $brief->drafts_count ? 'A draft is written. Review and publish it in Content Studio.' : 'A brief exists. Open Content Studio and click "Generate draft".';

            return $base + ['group' => 'planned', 'action' => 'Planned', 'summary' => $state, 'content_url' => route('content.index', $w)];
        }
        if ($k->intent === 'navigational' || preg_match('/\b(login|sign in|support|contact|reddit|youtube|github|jobs?|salary)\b/', $k->keyword)) {
            return $base + ['group' => 'skip', 'action' => 'Skip', 'summary' => 'People searching this want a specific website or a forum, not an article from you.'];
        }
        if ($context && preg_match('/^(does|is|can|will|how much does|how much is|why is|what is) '.preg_quote($context, '/').'\b(?!.*\b(app|apps)\b)/', $k->keyword)) {
            return $base + ['group' => 'skip', 'action' => 'Skip', 'summary' => 'A question about '.Str::ucfirst($context).' itself, not about apps like yours.'];
        }
        if (preg_match('/\bgift ?cards?\b/', $k->keyword) && ! in_array('card', $this->core, true)) {
            return $base + ['group' => 'skip', 'action' => 'Skip', 'summary' => 'Gift cards are a different product from free gifts; Growvia does not sell gift cards.'];
        }
        if (preg_match('/\b(api|sdk|developer|developers|liquid|graphql|webhook)\b/', $k->keyword)) {
            return $base + ['group' => 'skip', 'action' => 'Skip', 'summary' => 'Developers looking for code, not store owners choosing an app.'];
        }
        if (($k->opportunity_score ?? 0) < 45) {
            return $base + ['group' => 'skip', 'action' => 'Skip for now', 'summary' => 'Low opportunity. Focus on the higher-scoring phrases first.'];
        }
        if (! $k->pageTargets->first() && ($rival = $this->mentionsCompetitor($k->keyword))) {
            return array_merge($base, [
                'group' => 'todo', 'action' => 'Create a comparison page',
                'summary' => "People searching \"{$k->keyword}\" are looking at {$rival}. An honest comparison page can show them why Growvia may suit them better.",
                'steps' => ['Click "Create brief". The AI drafts a fair comparison (features, pricing, setup) for you to check.',
                    "Only state facts you can verify about {$rival}; link to their app listing.", 'Publish it, e.g. at /compare/'.Str::slug($rival).', and link to it from your Features page.'],
                'suggestion' => ['label' => 'Page title', 'text' => Str::limit("{$w->name} vs {$rival}: which Shopify app fits your store?", 70, ''), 'where' => 'a new comparison page'],
                'can_brief' => true,
            ]);
        }
        if ($relevance < 18) {
            return $base + ['group' => 'skip', 'action' => 'Skip', 'summary' => 'Off-topic for Growvia: few of its words match what your site is about.'];
        }
        if (! $k->pageTargets->first() && ! $this->hasCoreTopic($k->keyword)) {
            return $base + ['group' => 'skip', 'action' => 'Skip', 'summary' => 'Only loosely related: it does not mention any of the things Growvia does ('.implode(', ', array_slice($this->core, 0, 6)).'…).'];
        }

        if ($rec) {
            $p = $rec->proposal_json ?? [];
            $ai = $p['ai'] ?? [];
            [$action, $suggestion] = match ($rec->type) {
                'update_page' => ['Put it in the page title', ['label' => 'New SEO title', 'text' => $ai['title'] ?? $p['suggested_title'] ?? null, 'where' => "SEO title of {$path}"]],
                'add_section' => ['Add a section to the page', ['label' => 'New heading (H2)', 'text' => $ai['heading'] ?? $p['suggested_heading'] ?? null, 'where' => "a new section on {$path}"]],
                'new_landing' => ['Create a landing page', ['label' => 'Page title', 'text' => $ai['title'] ?? $p['suggested_title'] ?? null, 'where' => 'new page at '.($p['suggested_url'] ?? '/features/…')]],
                default => ['Write a blog post', ['label' => 'Post title', 'text' => $ai['title'] ?? $p['suggested_title'] ?? Str::ucfirst($k->keyword), 'where' => 'a new post on your blog']],
            };

            return array_merge($base, [
                'group' => 'todo', 'action' => $action, 'summary' => $rec->why, 'steps' => $rec->steps_json ?? [],
                'suggestion' => $suggestion['text'] ? $suggestion : null,
                'outline' => $ai['outline'] ?? null,
                'can_brief' => in_array($rec->type, ['new_landing', 'new_post'], true),
            ]);
        }

        if ($page) {
            $headings = implode(' | ', array_merge([$page->title], $page->headings_json['h1'] ?? [], $page->headings_json['h2'] ?? [], $page->headings_json['h3'] ?? []));
            if (TopicMatch::covers($headings, $k->keyword, $context)) {
                return $base + ['group' => 'done', 'action' => 'Nothing to do', 'summary' => "Your page {$path} already targets this in its title or headings."];
            }

            return array_merge($base, [
                'group' => 'todo', 'action' => 'Mention it on your page',
                'summary' => "{$path} is your best page for this search, but no title or heading mentions it.",
                'steps' => ["Open {$path} in your website admin.", 'Add a heading (H2) with the text below and 2–3 short paragraphs under it, using true facts about Growvia.'],
                'suggestion' => ['label' => 'New heading (H2)', 'text' => Str::ucfirst($k->keyword), 'where' => "a new section on {$path}"],
            ]);
        }

        // Shoppers comparing tools belong on product pages; learners on articles.
        $isArticle = fn ($p) => (bool) preg_match('#/(blog|guides?|learn|resources|articles?|docs|help)/#', (string) parse_url($p->canonical_url, PHP_URL_PATH));
        $preferred = in_array($k->intent, ['commercial', 'transactional'], true) ? $this->pages->reject($isArticle) : $this->pages->filter($isArticle);
        $near = $this->research->bestPageFor($preferred, $k->keyword);
        if (! $near || $near['score'] < 0.5) {
            $near = $this->research->bestPageFor($this->pages, $k->keyword);
        }
        if ($near && $near['score'] >= 0.5) {
            $nearPath = parse_url($near['url'], PHP_URL_PATH) ?: '/';

            return array_merge($base, [
                'group' => 'todo', 'action' => 'Add a section to an existing page', 'page_url' => $near['url'], 'page_path' => $nearPath,
                'summary' => "{$nearPath} is about this already. Adding a section is quicker than a new page and makes that page stronger.",
                'steps' => ["Open {$nearPath} in your website admin.", 'Add a heading (H2) with the text below.', 'Write 2–4 short paragraphs or a FAQ answer under it, using true facts about Growvia.'],
                'suggestion' => ['label' => 'New heading (H2)', 'text' => Str::ucfirst($k->keyword), 'where' => "a new section on {$nearPath}"],
            ]);
        }

        $landing = in_array($k->intent, ['commercial', 'transactional'], true);

        return array_merge($base, [
            'group' => 'todo',
            'action' => $landing ? 'Create a landing page' : 'Write a blog post',
            'summary' => $landing
                ? 'People search this when choosing a tool, and no page on your site targets it.'
                : 'People ask this and your site has no article answering it.',
            'steps' => ['Click "Create brief". The AI plans and drafts it in Content Studio.', 'Edit the draft, approve it, and publish it on your website.', 'Paste the published link in the draft so the app can check it.'],
            'suggestion' => ['label' => $landing ? 'Page title' : 'Post title', 'text' => Str::ucfirst($k->keyword), 'where' => $landing ? 'a new landing page' : 'a new blog post'],
            'can_brief' => true,
        ]);
    }
}
