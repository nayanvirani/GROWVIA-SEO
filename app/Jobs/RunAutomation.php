<?php

namespace App\Jobs;

use App\Domain\Automation\Automations;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public string $websiteId, public string $key, public string $trigger = 'schedule')
    {
        $this->onQueue('low');
    }

    public function handle(Automations $automations): void
    {
        if ($website = Website::find($this->websiteId)) {
            $automations->execute($website, $this->key, $this->trigger);
        }
    }
}
