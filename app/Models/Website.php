<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'crawl_enabled' => 'boolean',
        'ownership_confirmed' => 'boolean',
        'settings_json' => 'array',
    ];

    /** Defaults merged under settings_json; see section 7.2 and 24 of the guide. */
    public const DEFAULT_SETTINGS = [
        'crawl_max_pages' => 300,
        'crawl_max_depth' => 5,
        'crawl_frequency' => 'weekly',
        'exclusion_paths' => [],
        'brand_voice' => 'Clear, helpful and practical. No hype.',
        'description' => '',
        'brand_facts' => '',
        'forbidden_claims' => 'Guaranteed rankings, invented statistics, unverified integrations, fake testimonials.',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function setting(string $key): mixed
    {
        return ($this->settings_json ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    public function settings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings_json ?? []);
    }

    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    public function latestCrawlRun(): HasOne
    {
        return $this->hasOne(CrawlRun::class)->latestOfMany('created_at');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SeoIssue::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function keywordSeeds(): HasMany
    {
        return $this->hasMany(KeywordSeed::class);
    }

    public function keywordClusters(): HasMany
    {
        return $this->hasMany(KeywordCluster::class);
    }

    public function contentBriefs(): HasMany
    {
        return $this->hasMany(ContentBrief::class);
    }

    public function contentDrafts(): HasMany
    {
        return $this->hasMany(ContentDraft::class);
    }

    public function imageAssets(): HasMany
    {
        return $this->hasMany(ImageAsset::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    public function backlinkProspects(): HasMany
    {
        return $this->hasMany(BacklinkProspect::class);
    }

    public function backlinks(): HasMany
    {
        return $this->hasMany(Backlink::class);
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(AnalyticsDaily::class);
    }

    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(WebsiteVerification::class);
    }

    // Aliases used by scoped route-model binding (/websites/{website}/{param}).
    public function clusters(): HasMany
    {
        return $this->keywordClusters();
    }

    public function briefs(): HasMany
    {
        return $this->contentBriefs();
    }

    public function drafts(): HasMany
    {
        return $this->contentDrafts();
    }

    public function images(): HasMany
    {
        return $this->imageAssets();
    }

    public function prospects(): HasMany
    {
        return $this->backlinkProspects();
    }

    public function outreaches(): HasMany
    {
        return $this->hasMany(OutreachDraft::class);
    }
}
