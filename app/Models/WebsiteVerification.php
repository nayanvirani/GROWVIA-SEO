<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteVerification extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['verified_at' => 'datetime'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
