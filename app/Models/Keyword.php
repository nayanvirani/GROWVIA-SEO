<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'keywords';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'metric_date' => 'date',
        'opportunity_json' => 'array',
        'sources_json' => 'array',
        'is_gap' => 'boolean',
        'is_estimate' => 'boolean',
        'cpc' => 'decimal:2',
    ];

    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(KeywordCluster::class, 'keyword_cluster_items');
    }

    public function pageTargets(): HasMany
    {
        return $this->hasMany(PageKeywordTarget::class);
    }
}
