<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ListingSubmission extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = ['checked_at' => 'datetime'];

    public const STATUSES = ['todo', 'submitted', 'live', 'skipped'];
}
