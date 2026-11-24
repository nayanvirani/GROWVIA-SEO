<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StaleDraftsReminder extends Notification
{
    use Queueable;

    /** @param list<array{id: string, title: string}> $drafts */
    public function __construct(public Website $website, public array $drafts) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'stale_drafts',
            'website_id' => $this->website->id,
            'title' => count($this->drafts).' draft(s) waiting for your review',
            'body' => implode(', ', array_slice(array_column($this->drafts, 'title'), 0, 3)),
            'url' => route('content.index', $this->website, false),
        ];
    }
}
