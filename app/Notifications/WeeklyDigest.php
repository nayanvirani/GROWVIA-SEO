<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Website $website, public array $summary)
    {
        $this->onQueue('low');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_digest ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;

        return (new MailMessage)
            ->subject("Weekly SEO digest: {$this->website->name}")
            ->greeting("Your week on {$this->website->normalized_host}")
            ->line("New issues found: {$s['new_issues']} · Issues resolved: {$s['resolved_issues']}")
            ->line("Open high-severity issues: {$s['open_high']}")
            ->line("Drafts waiting for your review: {$s['drafts_waiting_review']}")
            ->line("Search clicks (last 7 days of available data): {$s['clicks_last_7d']}")
            ->line("Paid provider requests this week: {$s['provider_requests']}")
            ->action('Open SEO overview', route('websites.show', $this->website))
            ->line('Findings are diagnostics from your own crawl, not ranking guarantees.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'weekly_digest',
            'website_id' => $this->website->id,
            'title' => "Weekly digest for {$this->website->name}",
            'body' => "{$this->summary['new_issues']} new issues, {$this->summary['open_high']} high-severity open, {$this->summary['drafts_waiting_review']} drafts awaiting review.",
            'url' => route('websites.show', $this->website, false),
        ];
    }
}
