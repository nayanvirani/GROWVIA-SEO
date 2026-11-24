<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AnalyticsDaily extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'analytics_daily';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'date' => 'date',
        'ctr' => 'float',
        'position' => 'float',
    ];
}
