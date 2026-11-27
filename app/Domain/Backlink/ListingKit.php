<?php

namespace App\Domain\Backlink;

use App\Domain\AI\AiService;
use App\Domain\Keyword\SiteProfile;
use App\Models\ListingSubmission;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Support\ActivityLogger;
use App\Support\Security\SafeFetcher;
use App\Support\Url;
use Illuminate\Support\Str;

/**
 * Prepares everything needed to list the website on trusted software directories, tracks which
 * ones are done and verifies automatically that live listings link back to the site.
 */
class ListingKit
{
    public const FIELDS = [
        'name' => ['label' => 'Product name', 'max' => 60],
        'website' => ['label' => 'Website', 'max' => 200],
        'contact_email' => ['label' => 'Email for sign-ups', 'max' => 255],
        'tagline' => ['label' => 'Tagline', 'max' => 60],
        'short_description' => ['label' => 'Short description', 'max' => 160],
        'long_description' => ['label' => 'Full description', 'max' => 2000],
        'features' => ['label' => 'Key features', 'max' => null],
        'categories' => ['label' => 'Categories / tags', 'max' => null],
        'audience' => ['label' => 'Who it is for', 'max' => 200],
        'pricing' => ['label' => 'Pricing', 'max' => 200],
    ];

    public function __construct(private readonly AiService $ai) {}

    /** The ready-to-paste texts, generated once and kept in website settings. */
    public function pack(Website $website, bool $regenerate = false): array
    {
        $pack = $website->setting('listing_pack');
        if ($pack && ! $regenerate) {
            return $pack;
        }

        $facts = $this->facts($website);
        $pack = $this->heuristicPack($website, $facts);
        if ($this->ai->isReal($website)) {
            $aiError = null;
            $result = rescue(fn () => $this->ai->run($website, 'listing_pack', [
                'website_facts_untrusted' => $facts,
                'instructions' => 'Write directory listing texts for this product using ONLY the facts given. '
                    .'tagline <= 60 chars, short_description <= 160 chars, long_description 120-250 words in plain language, '
                    .'features = 5-10 short items, categories = 3-6 directory categories, audience = one sentence, '
                    .'pricing = only what the facts say (otherwise "See website"). No hype, no invented numbers or claims.',
            ]), function (\Throwable $e) use (&$aiError) {
                $aiError = $e->getMessage();

                return null;
            }, false);
            foreach (['tagline', 'short_description', 'long_description', 'features', 'categories', 'audience', 'pricing'] as $key) {
                if (! empty($result['data'][$key])) {
                    $pack[$key] = $result['data'][$key];
                }
            }
            $pack['source'] = $result ? 'ai' : 'website';
            $pack['ai_note'] = $aiError ? 'AI was unavailable, so these texts come from your website: '.Str::limit($aiError, 160).' Click Rewrite later.' : null;
        }

        foreach (self::FIELDS as $key => $f) {
            if ($f['max'] && is_string($pack[$key] ?? null)) {
                $pack[$key] = Str::limit(trim($pack[$key]), $f['max'] - 1, '…');
            }
        }
        $pack['generated_at'] = now()->toIso8601String();
        $website->update(['settings_json' => array_merge($website->settings_json ?? [], ['listing_pack' => $pack])]);

        return $pack;
    }

    /** Platforms with this website's progress, best first. */
    public function platforms(Website $website): array
    {
        $rows = $website->listings()->get()->keyBy('platform');

        return collect(config('listings'))->map(fn ($p, $key) => $p + ['key' => $key, 'submission' => $rows[$key] ?? null])
            ->sortBy('priority')->values()->all();
    }

    public function update(Website $website, string $platform, array $data): ListingSubmission
    {
        abort_unless(config("listings.{$platform}"), 404);
        $row = ListingSubmission::firstOrNew(['website_id' => $website->id, 'platform' => $platform]);
        $row->fill($data);
        $row->save();
        ActivityLogger::log($website, 'listing.updated', $row, ['platform' => $platform, 'status' => $row->status]);

        if ($row->listing_url && $row->status !== 'skipped' && config("listings.{$platform}.verify")) {
            $this->verify($row);
        }

        return $row->fresh();
    }

    /** Open the public listing and confirm it links to the website. */
    public function verify(ListingSubmission $row): ListingSubmission
    {
        $website = $row->website;
        $res = SafeFetcher::get($row->listing_url);
        $linked = false;
        if ($res['html']) {
            foreach ($this->hrefs($res['html']) as $href) {
                if (rescue(fn () => Url::host($href), null, false) === $website->normalized_host) {
                    $linked = $href;
                    break;
                }
            }
        }

        if ($linked) {
            $row->update(['status' => 'live', 'checked_at' => now(), 'check_result' => 'Listing is live and links to your website.']);
            $website->backlinks()->updateOrCreate(
                ['source_url' => Url::normalize($res['final_url']), 'target_url' => Url::normalize($linked)],
                ['source_domain' => Url::host($res['final_url']), 'first_seen_at' => now(), 'last_seen_at' => now(), 'status' => 'active', 'provider' => 'listing']
            );
        } else {
            $row->update(['checked_at' => now(), 'check_result' => $res['html']
                ? 'The page loads but no link to your website was found yet. Listings are sometimes reviewed before they go live.'
                : 'This site blocks automatic checks ('.($res['error'] ?? 'HTTP '.$res['status']).'). Open your listing and mark it live yourself.']);
        }

        return $row->fresh();
    }

    /** Re-check every submitted or live listing (weekly). */
    public function verifyAll(Website $website): array
    {
        $checked = $live = 0;
        foreach ($website->listings()->whereIn('status', ['submitted', 'live'])->whereNotNull('listing_url')->get() as $row) {
            if (config("listings.{$row->platform}.verify")) {
                $checked++;
                $live += $this->verify($row)->status === 'live' ? 1 : 0;
            }
        }

        return compact('checked', 'live');
    }

    private function facts(Website $website): array
    {
        $pages = WebsitePage::forWebsite($website)->where('status_code', 200)->orderBy('depth')->limit(200)
            ->get(['canonical_url', 'title', 'meta_description', 'h1']);
        $home = $pages->first(fn ($p) => in_array(parse_url($p->canonical_url, PHP_URL_PATH) ?: '/', ['/', ''], true));
        $byPath = fn (string $prefix) => $pages->filter(fn ($p) => str_starts_with((string) parse_url($p->canonical_url, PHP_URL_PATH), $prefix));

        return [
            'name' => $website->name,
            'website' => $website->base_url,
            'owner_description' => $website->setting('description'),
            'approved_brand_facts' => $website->setting('brand_facts'),
            'home' => $home ? ['title' => $home->title, 'description' => $home->meta_description, 'h1' => $home->h1] : null,
            'feature_pages' => $byPath('/features/')->take(15)->map(fn ($p) => ['title' => $p->title, 'description' => $p->meta_description])->values()->all(),
            'pricing_page' => $byPath('/pricing')->map(fn ($p) => ['title' => $p->title, 'description' => $p->meta_description])->first(),
            'solutions' => $byPath('/solutions/')->take(8)->pluck('title')->values()->all(),
        ];
    }

    /** Works offline: assembled only from the website's own titles and descriptions. */
    private function heuristicPack(Website $website, array $facts): array
    {
        $clean = fn (?string $t) => trim(preg_replace('/\s*[|:–—-]\s*'.preg_quote($website->name, '/').'.*$/iu', '', (string) $t), ' |–—-');
        $features = collect($facts['feature_pages'])->map(fn ($f) => $clean(Str::before($f['title'], ':')))
            ->map(fn ($t) => trim(preg_replace('/^'.preg_quote((string) SiteProfile::for($website)->context, '/').'\s+/i', '', $t)))
            ->filter()->unique()->take(10)->values()->all();
        $home = $facts['home'] ?? [];
        $short = $home['description'] ?? $website->setting('description') ?? '';
        $profile = SiteProfile::for($website);

        return [
            'name' => $website->name,
            'website' => $website->base_url,
            'tagline' => $clean($home['title'] ?? $website->website_type ?? $website->name),
            'short_description' => $short,
            'long_description' => trim(implode("\n\n", array_filter([
                $short,
                $features ? $website->name.' includes: '.implode(', ', $features).'.' : null,
                $website->setting('brand_facts') ?: null,
            ]))),
            'features' => $features,
            'categories' => array_values(array_map(fn ($p) => Str::title($p), array_slice($profile->phrases, 0, 5))),
            'audience' => $profile->context ? Str::title($profile->context).' store owners who want to raise order value and sales' : 'Online store owners',
            'pricing' => $facts['pricing_page'] ? preg_replace('/^(pricing|plans|prices)\s*[:|–-]\s*/i', '', $clean($facts['pricing_page']['title'])) : 'See website',
            'source' => 'website',
        ];
    }

    /** @return list<string> */
    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $m);

        return array_values(array_filter($m[1], fn ($h) => preg_match('#^https?://#i', $h)));
    }
}
