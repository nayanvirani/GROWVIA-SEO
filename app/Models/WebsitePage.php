<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsitePage extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'website_pages';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'headings_json' => 'array',
        'redirect_chain_json' => 'array',
        'images_json' => 'array',
        'last_crawled_at' => 'datetime',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(PageLink::class, 'source_page_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SeoIssue::class, 'page_id');
    }

    public function isHtml(): bool
    {
        return str_contains((string) $this->content_type, 'html');
    }

    public function isIndexable(): bool
    {
        $robots = strtolower($this->robots_meta.' '.$this->x_robots_tag);

        return $this->status_code >= 200 && $this->status_code < 300 && ! str_contains($robots, 'noindex');
    }
}
