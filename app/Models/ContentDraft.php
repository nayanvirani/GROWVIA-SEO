<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentDraft extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'content_drafts';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'faq_json' => 'array',
        'internal_links_json' => 'array',
        'quality_flags_json' => 'array',
        'source_pages_json' => 'array',
        'approved_at' => 'datetime',
        'exported_at' => 'datetime',
    ];

    public function brief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'brief_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class)->latest();
    }

    public function images(): HasMany
    {
        return $this->hasMany(ImageAsset::class);
    }
}
