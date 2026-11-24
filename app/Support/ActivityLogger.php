<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\ProviderUsage;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;

final class ActivityLogger
{
    public static function log(Website $website, string $action, ?Model $subject = null, array $metadata = []): void
    {
        ActivityLog::create([
            'workspace_id' => $website->workspace_id,
            'website_id' => $website->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata_json' => $metadata ?: null,
        ]);
    }

    public static function usage(Website $website, string $provider, string $operation, int $units = 0, float $cost = 0.0): void
    {
        ProviderUsage::create([
            'workspace_id' => $website->workspace_id,
            'website_id' => $website->id,
            'provider' => $provider,
            'operation' => $operation,
            'request_count' => 1,
            'units' => $units,
            'estimated_cost' => $cost,
        ]);
    }
}
