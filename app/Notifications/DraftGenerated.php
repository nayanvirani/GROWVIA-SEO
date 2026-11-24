<?php

namespace App\Notifications;

use App\Models\ContentDraft;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DraftGenerated extends Notification
{
    use Queueable;

    public function __construct(public ContentDraft $draft) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'draft_generated',
            'website_id' => $this->draft->website_id,
            'title' => 'Draft ready for review',
            'body' => $this->draft->title,
            'url' => route('content.drafts.edit', [$this->draft->website_id, $this->draft->id], false),
        ];
    }
}
