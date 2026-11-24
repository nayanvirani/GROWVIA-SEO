<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WorkspaceInvitation $invitation, public string $token)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $i = $this->invitation->loadMissing('workspace', 'inviter');

        return (new MailMessage)
            ->subject("You're invited to {$i->workspace->name} on ".config('app.name'))
            ->line(($i->inviter?->name ?? 'A teammate')." invited you to join the {$i->workspace->name} workspace as {$i->role}.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires on '.$i->expires_at->toFormattedDateString().'. If you did not expect it, ignore this email.');
    }
}
