<?php

namespace App\Domain\Content;

use App\Domain\AI\AiService;
use App\Domain\Keyword\SiteProfile;
use App\Models\ContentBrief;
use App\Models\Keyword;
use App\Models\Recommendation;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "What to update on your website": concrete page-level suggestions built from crawled pages and
 * keyword research. The owner makes the changes; recommendations resolve themselves when the next
 * crawl shows the change.
 */
class Recommender
{
    private const SKIP_SECTIONS = ['legal', 'privacy', 'terms', 'cookies', 'dpa', 'contact', 'login', 'signup', 'account', 'docs', 'help', 'security', 'about'];

    /** "add", "create", "set up"... describe the same task; they do not decide whether a page covers a topic. */
    private const ACTION_WORDS = ['add', 'adding', 'create', 'creating', 'make', 'making', 'set', 'setup', 'build', 'use', 'using', 'get', 'enable', 'install', 'start'];

    private SiteProfile $profile;

    private array $found = [];

    public function __construct(private readonly AiService $ai) {}

    public function generate(Website $website): array
    {
        $this->profile = SiteProfile::for($website);
        $this->found = [];
        $pages = WebsitePage::forWebsite($website)->where('status_code', 200)->get()
            ->reject(fn ($p) => in_array($this->section($p->canonical_url), self::SKIP_SECTIONS, true))->keyBy('id');
        $keywords = Keyword::forWebsite($website)->with(['pageTargets', 'clusters:id'])->whereNotNull('opportunity_score')->get();

        $this->pageUpdates($pages, $keywords);
        $this->newContent($website, $keywords);
        $this->refresh($pages);

        $created = 0;
        foreach ($this->found as $fp => $r) {
            $rec = Recommendation::firstOrNew(['website_id' => $website->id, 'fingerprint' => $fp]);
            if (! $rec->exists) {
                $created++;
            }
            $rec->fill($r);
            if ($rec->status === 'resolved') {
                $rec->status = 'open'; // came back
                $rec->resolved_at = null;
            }
            $rec->save();
        }
        // Open recommendations no longer produced = the owner made the change (or data changed).
        $resolved = Recommendation::forWebsite($website)->where('status', 'open')->whereNotIn('fingerprint', array_keys($this->found))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        $proposals = $this->writeProposals($website);
        ActivityLogger::log($website, 'recommendations.generated', null, compact('created', 'resolved', 'proposals'));

        return ['open' => Recommendation::forWebsite($website)->where('status', 'open')->count(), 'new' => $created, 'resolved' => $resolved, 'ai_proposals' => $proposals];
    }

    /** 1) Title missing the page's best search phrase. 2) Related searches the page has no section for. */
    private function pageUpdates(Collection $pages, Collection $keywords): void
    {
        $byPage = $keywords->filter(fn ($k) => $k->pageTargets->isNotEmpty() && $k->opportunity_score >= 55)
            ->groupBy(fn ($k) => $k->pageTargets->first()->page_id);

        foreach ($byPage as $pageId => $kws) {
            $page = $pages[$pageId] ?? null;
            if (! $page) {
                continue;
            }
            $kws = $kws->sortByDesc('opportunity_score')->values();
            $best = $kws->first();
            if ($best->intent !== 'informational' && ! $this->covers($page->title.' '.$page->h1, $best->keyword)) {
                $this->add('update_page', $page, $best, "Put \"{$best->keyword}\" in the title of {$this->path($page)}",
                    "This page is your best match for \"{$best->keyword}\" (opportunity {$best->opportunity_score}), but its title does not say so. Titles are the strongest on-page signal and what searchers see in results.",
                    ['Open the page in your website admin.', "Change the SEO title so it includes \"{$best->keyword}\" naturally (proposal below).", 'Keep it under about 60 characters.'],
                    ['current_title' => $page->title, 'suggested_title' => $this->suggestTitle($page, $best->keyword)]);
            }

            if (preg_match('#^/[^/]*/?$#', $this->path($page)) && in_array($this->section($page->canonical_url), ['blog', 'resources', 'guides', 'learn', 'news'], true)) {
                continue; // hub page: searches mapped here need their own articles
            }
            $headings = Str::lower(implode(' | ', array_merge([$page->title], $page->headings_json['h1'] ?? [], $page->headings_json['h2'] ?? [], $page->headings_json['h3'] ?? [])));
            $missing = $kws->slice(1)->filter(fn ($k) => ! $this->covers($headings, $k->keyword))
                ->unique(fn ($k) => $this->stemKey($k->keyword))
                ->unique(fn ($k) => $k->clusters->first()?->id ?? $k->id)->take(3);
            foreach ($missing as $k) {
                $this->add('add_section', $page, $k, "Add a section about \"{$k->keyword}\" to {$this->path($page)}",
                    "People search \"{$k->keyword}\" and this is the page that should answer it, but no heading on the page covers it. A short section (or FAQ answer) helps the page rank for this search too.",
                    ['Add a heading (H2) about this topic to the page.', 'Write 2–4 short paragraphs or a FAQ answer under it, using only true facts about Growvia.'],
                    ['suggested_heading' => Str::ucfirst($k->keyword)]);
            }
        }
    }

    /** 3) Landing pages for product searches you have no page for. 4) Blog posts for questions you do not answer. */
    private function newContent(Website $website, Collection $keywords): void
    {
        $briefed = ContentBrief::forWebsite($website)->whereNotNull('primary_keyword_id')->pluck('primary_keyword_id')->flip();
        $gaps = $keywords->filter(fn ($k) => $k->is_gap && ! isset($briefed[$k->id]) && $k->intent !== 'navigational'
            && ($k->opportunity_json['relevance']['points'] ?? 0) >= 24 && ! preg_match('/\b(reddit|youtube|github|pdf|login|jobs?)\b/', $k->keyword))
            ->sortByDesc('opportunity_score')->unique(fn ($k) => $k->clusters->first()?->id ?? $k->id);

        foreach ($gaps->whereIn('intent', ['commercial', 'transactional'])->where('opportunity_score', '>=', 65)->take(4) as $k) {
            $slug = Str::slug(trim(preg_replace('/\b(best|top|'.preg_quote((string) $this->profile->context, '/').'|app|apps|for)\b/i', ' ', $k->keyword))) ?: Str::slug($k->keyword);
            $this->add('new_landing', null, $k, "Create a landing page for \"{$k->keyword}\"",
                "Shoppers search \"{$k->keyword}\" when they are looking for a solution, and no page on your site targets it. A focused page can rank for it and convert those visitors.",
                ['Create a new page, e.g. /features/'.$slug.' (or a solutions page).', "Use \"{$k->keyword}\" in the title and main heading.", 'Explain the problem, how Growvia solves it, setup steps, and a FAQ. Add a link to it from your Features page.'],
                ['suggested_url' => '/features/'.$slug, 'suggested_title' => Str::limit(Str::title($k->keyword).' | '.$website->name, 60, '')]);
        }

        foreach ($gaps->where('intent', 'informational')->where('opportunity_score', '>=', 55)->take(4) as $k) {
            $this->add('new_post', null, $k, 'Write a blog post: "'.Str::ucfirst($k->keyword).'"',
                'People ask this and your site has no article answering it. A helpful guide brings visitors who may need Growvia next.',
                ['Click "Create brief" to plan it in Content Studio (AI drafts it for you to edit).', 'Publish it on your blog, then paste the link in the draft so the app can check it.'],
                ['suggested_title' => Str::ucfirst($k->keyword)]);
        }
    }

    /** 5) Titles with last year's (or soon-outdated) year, and very short articles. */
    private function refresh(Collection $pages): void
    {
        $year = (int) now()->year;
        $target = now()->month >= 11 ? $year + 1 : $year;
        foreach ($pages as $page) {
            if (preg_match_all('/\b(20[2-3]\d)\b/', (string) $page->title.' '.$page->h1, $m)) {
                $old = max(array_map('intval', $m[1]));
                if ($old < $target) {
                    $this->add('refresh', $page, null, "Update {$this->path($page)} to {$target}",
                        "The title says {$old}. Searchers prefer current guides, and \"{$target}\" searches start well before January.",
                        ["Update the year in the title and heading to {$target}.", 'Re-check facts, prices and screenshots in the article.', 'Change the "last updated" date if your blog shows one.'],
                        ['current_title' => $page->title, 'suggested_title' => preg_replace('/\b'.$old.'\b/', (string) $target, (string) $page->title)], "year:{$target}");
                }
            }
            if ($this->section($page->canonical_url) === 'blog' && substr_count(trim((string) parse_url($page->canonical_url, PHP_URL_PATH), '/'), '/') >= 1 && $page->word_count > 0 && $page->word_count < 600) {
                $this->add('refresh', $page, null, "Expand the short article {$this->path($page)}",
                    "It has about {$page->word_count} words. Articles that fully answer the question usually rank better than short ones.",
                    ['Add the missing steps, examples or a FAQ until the question is fully answered (often 900+ words).'], null, 'thin');
            }
        }
    }

    private function add(string $type, ?WebsitePage $page, ?Keyword $kw, string $title, string $why, array $steps, ?array $proposal = null, string $extra = ''): void
    {
        $fp = hash('sha256', $type.'|'.($page?->id).'|'.($kw?->id).'|'.$extra);
        $score = (int) ($kw?->opportunity_score ?? ($type === 'refresh' ? 60 : 50));
        $this->found[$fp] = [
            'type' => $type, 'page_id' => $page?->id, 'keyword_id' => $kw?->id, 'title' => Str::limit($title, 250, ''),
            'why' => $why, 'steps_json' => $steps, 'score' => $score,
            'priority' => $score >= 75 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
            'proposal_json' => $proposal,
        ];
    }

    /** Ask the AI for ready-to-paste text for the most valuable open recommendations (5 per run). */
    private function writeProposals(Website $website): int
    {
        if (! $this->ai->isReal($website)) {
            return 0;
        }
        $done = 0;
        $recs = Recommendation::forWebsite($website)->where('status', 'open')->with(['page', 'keyword'])->orderByDesc('score')->get()
            ->filter(fn ($r) => empty($r->proposal_json['ai']))->take(5);
        foreach ($recs as $rec) {
            $result = rescue(fn () => $this->ai->run($website, 'page_update', [
                'recommendation' => $rec->title,
                'type' => $rec->type,
                'target_keyword' => $rec->keyword?->keyword,
                'page_url' => $rec->page?->canonical_url,
                'current_title' => $rec->page?->title,
                'current_headings' => $rec->page ? array_slice(array_merge($rec->page->headings_json['h1'] ?? [], $rec->page->headings_json['h2'] ?? []), 0, 15) : null,
                'page_content_excerpt_untrusted' => $rec->page ? Str::limit((string) $rec->page->text_excerpt, 2500) : null,
                'instructions' => 'Write ready-to-paste text for this website change using ONLY facts from the brand facts and page. '
                    .'Return JSON {"summary":"one sentence","title":"SEO title <= 60 chars or null","meta_description":"<= 155 chars or null",'
                    .'"heading":"H1/H2 text or null","outline":["section headings"],"faq":[{"question":"","answer":""}]}. '
                    .'Mark any fact you are unsure of with [check].',
            ]), null, false);
            if ($result) {
                $rec->update(['proposal_json' => array_merge($rec->proposal_json ?? [], ['ai' => $result['data'], 'ai_model' => $result['model']])]);
                $done++;
            }
        }

        return $done;
    }

    private function covers(string $text, string $keyword): bool
    {
        $text = ' '.Str::lower(str_replace(['-', '–', '&'], ' ', $text)).' ';
        $words = collect(SiteProfile::words($keyword))
            ->reject(fn ($w) => in_array($w, SiteProfile::STOPWORDS, true) || $w === $this->profile->context || SiteProfile::isNoise($w)
                || in_array($w, self::ACTION_WORDS, true));
        if ($words->isEmpty()) {
            return true;
        }

        return $words->every(fn ($w) => str_contains($text, ' '.preg_replace('/(ies|s)$/', '', $w)));
    }

    /** "shopify bundles app" and "best shopify bundle app" -> same key. */
    private function stemKey(string $keyword): string
    {
        return collect(SiteProfile::words($keyword))
            ->reject(fn ($w) => in_array($w, SiteProfile::STOPWORDS, true) || $w === $this->profile->context || SiteProfile::isNoise($w))
            ->map(fn ($w) => preg_replace('/(ies|s)$/', '', $w))->unique()->sort()->implode(' ');
    }

    private function suggestTitle(WebsitePage $page, string $keyword): string
    {
        $brand = str_contains((string) $page->title, '|') ? trim(Str::afterLast($page->title, '|')) : $page->website->name;

        return Str::limit(Str::title($keyword).' | '.$brand, 60, '');
    }

    private function section(string $url): string
    {
        return Str::lower(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))[0] ?? '');
    }

    private function path(WebsitePage $page): string
    {
        return parse_url($page->canonical_url, PHP_URL_PATH) ?: '/';
    }
}
