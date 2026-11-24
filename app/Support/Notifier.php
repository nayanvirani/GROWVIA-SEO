<?php

namespace App\Support;

use App\Models\Website;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

final class Notifier
{
    /** Notify every member of the website's workspace. */
    public static function website(Website $website, BaseNotification $notification): void
    {
        $members = $website->workspace->members()->get();
        if ($members->isNotEmpty()) {
            Notification::send($members, $notification);
        }
    }
}
