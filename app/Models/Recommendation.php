<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = ['steps_json' => 'array', 'proposal_json' => 'array', 'resolved_at' => 'datetime'];

    public const TYPES = [
        'update_page' => 'Update a page',
        'add_section' => 'Add a section',
        'new_landing' => 'New landing page',
        'new_post' => 'New blog post',
        'refresh' => 'Refresh content',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'page_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
