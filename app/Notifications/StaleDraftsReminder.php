<?php

namespace App\Notifications;

use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaleDraftsReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{id: string, title: string}> $drafts */
    public function __construct(public Website $website, public array $drafts)
    {
        $this->onQueue('low');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_reminders ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(count($this->drafts)." draft(s) waiting for review on {$this->website->name}")
            ->line('These drafts have not been touched for over 14 days:');
        foreach (array_slice($this->drafts, 0, 10) as $d) {
            $mail->line('• '.$d['title']);
        }

        return $mail->action('Open content studio', route('content.index', $this->website));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'stale_drafts',
            'website_id' => $this->website->id,
            'title' => count($this->drafts).' draft(s) waiting for review',
            'body' => implode(', ', array_slice(array_column($this->drafts, 'title'), 0, 3)),
            'url' => route('content.index', $this->website, false),
        ];
    }
}
