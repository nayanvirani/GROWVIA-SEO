<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachDraft extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'outreach_drafts';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(BacklinkProspect::class, 'prospect_id');
    }
}
