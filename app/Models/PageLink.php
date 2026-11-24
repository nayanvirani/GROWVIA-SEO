<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageLink extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'page_links';

    protected $guarded = ['id', 'workspace_id'];

    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'source_page_id');
    }
}
