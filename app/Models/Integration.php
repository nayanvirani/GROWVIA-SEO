<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Workspace-level record; website_id is optional. */
class Integration extends Model
{
    use HasUlids;

    protected $table = 'integrations';

    protected $guarded = ['id'];

    protected $casts = [
        'encrypted_credentials' => 'encrypted:array',
        'metadata_json' => 'array',
    ];

    /** Credentials never leave the server. */
    protected $hidden = ['encrypted_credentials'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
