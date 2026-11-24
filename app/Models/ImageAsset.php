<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageAsset extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'image_assets';

    protected $guarded = ['id', 'workspace_id'];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ContentDraft::class, 'content_draft_id');
    }
}
