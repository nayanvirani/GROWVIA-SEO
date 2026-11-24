<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Workspace-level record; website_id is optional. */
class ProviderUsage extends Model
{
    use HasUlids;

    protected $table = 'provider_usage';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['estimated_cost' => 'float'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
