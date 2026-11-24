<?php

namespace Tests\Feature;

use App\Domain\Automation\Automations;
use App\Models\ContentDraft;
use App\Models\User;
use App\Notifications\StaleDraftsReminder;
use App\Notifications\WeeklyDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_and_reminders_are_in_app_only_for_workspace_members()
    {
        Notification::fake();
        [$owner, $website] = $this->userWithWebsite();
        $member = User::factory()->create();
        $website->workspace->members()->attach($member->id, ['role' => 'editor']);
        $outsider = User::factory()->create();

        app(Automations::class)->execute($website, 'weekly_digest', 'manual');
        Notification::assertSentTo([$owner, $member], WeeklyDigest::class, fn ($n, $channels) => $channels === ['database']);
        Notification::assertNotSentTo($outsider, WeeklyDigest::class);

        app(Automations::class)->execute($website, 'stale_draft_reminder', 'manual');
        Notification::assertNotSentTo($owner, StaleDraftsReminder::class);

        $draft = ContentDraft::create(['website_id' => $website->id, 'type' => 'blog_post', 'title' => 'Old draft', 'status' => 'content_draft']);
        ContentDraft::whereKey($draft->id)->update(['updated_at' => now()->subDays(20)]);
        app(Automations::class)->execute($website, 'stale_draft_reminder', 'manual');
        Notification::assertSentTo($owner, StaleDraftsReminder::class, fn ($n, $channels) => $n->drafts[0]['title'] === 'Old draft' && $channels === ['database']);
    }

    public function test_crawl_notification_bell_and_open_flow_without_any_email()
    {
        Mail::fake();
        [$user, $website] = $this->userWithWebsite(['settings_json' => ['autopilot' => false]]);
        $this->fakeCrawler([$this->crawledPage('/')]);
        $this->actingAs($user)->post(route('audit.crawl', $website));

        $this->assertSame(1, $user->unreadNotifications()->count());
        $notification = $user->notifications()->first();
        $this->assertSame('crawl_finished', $notification->data['type']);

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('notifications/Index')->where('unreadNotifications', 1));
        $this->actingAs($user)->get(route('notifications.open', $notification->id))->assertRedirect(route('audit.index', $website, false));
        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->actingAs(User::factory()->create())->get(route('notifications.open', $notification->id))->assertNotFound();
        Mail::assertNothingOutgoing();
    }
}
