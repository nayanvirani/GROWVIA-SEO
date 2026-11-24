<?php

namespace Tests\Feature;

use App\Domain\Automation\Automations;
use App\Models\ContentDraft;
use App\Models\User;
use App\Notifications\CrawlFinished;
use App\Notifications\StaleDraftsReminder;
use App\Notifications\WeeklyDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_and_stale_reminders_go_to_workspace_members_and_respect_email_preferences()
    {
        Notification::fake();
        [$owner, $website] = $this->userWithWebsite();
        $member = User::factory()->create(['email_digest' => false]);
        $website->workspace->members()->attach($member->id, ['role' => 'editor']);
        $outsider = User::factory()->create();

        app(Automations::class)->execute($website, 'weekly_digest', 'manual');

        Notification::assertSentTo($owner, WeeklyDigest::class, fn ($n, $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($member, WeeklyDigest::class, fn ($n, $channels) => $channels === ['database']);
        Notification::assertNotSentTo($outsider, WeeklyDigest::class);

        // No stale drafts -> no reminder.
        app(Automations::class)->execute($website, 'stale_draft_reminder', 'manual');
        Notification::assertNotSentTo($owner, StaleDraftsReminder::class);

        $draft = ContentDraft::create(['website_id' => $website->id, 'type' => 'blog_post', 'title' => 'Old draft', 'status' => 'content_draft']);
        ContentDraft::whereKey($draft->id)->update(['updated_at' => now()->subDays(20)]);
        app(Automations::class)->execute($website, 'stale_draft_reminder', 'manual');
        Notification::assertSentTo($owner, StaleDraftsReminder::class, fn ($n) => $n->drafts[0]['title'] === 'Old draft');
    }

    public function test_crawl_finished_notification_bell_and_open_flow()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->fakeCrawler([$this->crawledPage('/')]);
        $this->actingAs($user)->post(route('audit.crawl', $website));

        $this->assertSame(1, $user->unreadNotifications()->count());
        $notification = $user->notifications()->first();
        $this->assertSame('crawl_finished', $notification->data['type']);

        $this->actingAs($user)->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('notifications/Index')->where('unreadNotifications', 1));

        $this->actingAs($user)->get(route('notifications.open', $notification->id))->assertRedirect(route('audit.index', $website, false));
        $this->assertSame(0, $user->unreadNotifications()->count());

        // Another user's notification is not reachable.
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('notifications.open', $notification->id))->assertNotFound();
    }

    public function test_email_preferences_can_be_updated_and_mail_renders()
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->get(route('notifications.preferences'))->assertOk();
        $this->actingAs($user)->patch(route('notifications.preferences.update'), ['email_digest' => false, 'email_reminders' => true])->assertRedirect();
        $this->assertFalse($user->fresh()->email_digest);

        $mail = (new WeeklyDigest($website, ['new_issues' => 2, 'resolved_issues' => 1, 'open_high' => 3, 'drafts_waiting_review' => 0, 'clicks_last_7d' => 40, 'provider_requests' => 5]))->toMail($user);
        $this->assertStringContainsString('Weekly SEO digest', $mail->subject);
        $this->assertNotEmpty((string) $mail->render());
        $this->assertSame('crawl_finished', (new CrawlFinished($website->crawlRuns()->create(['status' => 'failed', 'error_summary' => ['message' => 'x']])->load('website')))->toArray($user)['type']);
    }
}
