<?php

namespace App\Models\Concerns;

use App\Models\Website;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour for every website-owned record: tenant relations and a scope.
 * workspace_id is always derived from the website so the two can never disagree.
 */
trait BelongsToWebsite
{
    public static function bootBelongsToWebsite(): void
    {
        static::creating(function ($model) {
            if (! $model->workspace_id && $model->website_id) {
                $model->workspace_id = Website::query()->whereKey($model->website_id)->value('workspace_id');
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function scopeForWebsite(Builder $query, Website|string $website): Builder
    {
        return $query->where($this->getTable().'.website_id', $website instanceof Website ? $website->getKey() : $website);
    }
}
