<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app weekly summary (no email: the app is for personal use). */
class WeeklyDigest extends Notification
{
    use Queueable;

    public function __construct(public Website $website, public array $summary) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $s = $this->summary;

        return [
            'type' => 'weekly_digest',
            'website_id' => $this->website->id,
            'title' => "Weekly summary for {$this->website->name}",
            'body' => "{$s['new_issues']} new issues, {$s['resolved_issues']} fixed, {$s['open_high']} important issues open, "
                ."{$s['drafts_waiting_review']} drafts to review, {$s['new_prospects']} new link opportunities.",
            'url' => route('websites.show', $this->website, false),
        ];
    }
}
