<?php

namespace App\Jobs;

use App\Domain\Automation\Automations;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSearchConsole implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public string $websiteId, public string $trigger = 'manual')
    {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return $this->websiteId;
    }

    public function handle(Automations $automations): void
    {
        if ($website = Website::find($this->websiteId)) {
            $automations->execute($website, 'search_console_sync', $this->trigger);
        }
    }
}
