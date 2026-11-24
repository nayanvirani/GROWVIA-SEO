<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWebsite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CrawlRun extends Model
{
    use BelongsToWebsite, HasUlids;

    protected $table = 'crawl_runs';

    protected $guarded = ['id', 'workspace_id'];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'error_summary' => 'array',
        'sitemaps_json' => 'array',
    ];

    public const ACTIVE_STATUSES = ['queued', 'running'];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
