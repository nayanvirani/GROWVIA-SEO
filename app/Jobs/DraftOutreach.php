<?php

namespace App\Jobs;

use App\Domain\Backlink\BacklinkService;
use App\Models\BacklinkProspect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Writes one outreach message in the background so discovery runs stay fast. */
class DraftOutreach implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [120];

    public int $timeout = 420;

    public function __construct(public string $prospectId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->prospectId;
    }

    public function handle(BacklinkService $backlinks): void
    {
        $prospect = BacklinkProspect::find($this->prospectId);
        if ($prospect && $prospect->status !== 'rejected' && ! $prospect->outreachDrafts()->exists()) {
            $backlinks->outreachDraft($prospect);
        }
    }
}
