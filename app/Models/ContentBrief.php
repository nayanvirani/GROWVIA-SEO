<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentBrief extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'content_briefs';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = ['outline_json' => 'array'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'page_id');
    }

    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(ContentDraft::class, 'brief_id');
    }
}
