<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KeywordCluster extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'keyword_clusters';

    protected $guarded = ['id', 'workspace_id'];

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'keyword_cluster_items');
    }

    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }
}
