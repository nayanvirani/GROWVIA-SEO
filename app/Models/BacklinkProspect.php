<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacklinkProspect extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'backlink_prospects';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'score_breakdown_json' => 'array',
        'risk_flags_json' => 'array',
        'evidence_json' => 'array',
    ];

    public const STATUSES = ['discovered', 'reviewed', 'approved', 'contacted', 'replied', 'placed', 'rejected'];

    public function outreachDrafts(): HasMany
    {
        return $this->hasMany(OutreachDraft::class, 'prospect_id');
    }
}
