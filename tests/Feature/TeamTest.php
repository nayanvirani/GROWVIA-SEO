<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function inviteAndCaptureToken(User $owner, string $email, string $role): string
    {
        $token = null;
        Notification::fake();
        $this->actingAs($owner)->post(route('team.invite'), ['email' => $email, 'role' => $role])->assertSessionHasNoErrors();
        Notification::assertSentOnDemand(WorkspaceInvitationSent::class, function ($n, $channels, $notifiable) use ($email, &$token) {
            $token = $n->token;

            return $notifiable->routes['mail'] === strtolower($email);
        });

        return $token;
    }

    public function test_invite_accept_and_role_based_access()
    {
        [$owner, $website] = $this->userWithWebsite();
        $token = $this->inviteAndCaptureToken($owner, 'Viewer@Example.com', 'viewer');
        $this->assertDatabaseMissing('workspace_invitations', ['token_hash' => $token]); // only the hash is stored

        // Guest sees the landing page and is sent back to it after login.
        $this->app['auth']->guard('web')->logout();
        $this->get(route('invitations.show', $token))->assertOk()->assertInertia(fn ($p) => $p->where('invitation.valid', true));
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $this->post('/login', ['email' => 'viewer@example.com', 'password' => 'password'])->assertRedirect(route('invitations.show', $token));

        $this->actingAs($viewer)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
        $this->assertSame('viewer', $viewer->workspaceRole($website->workspace_id));
        $this->assertSame($website->workspace_id, $viewer->fresh()->current_workspace_id);

        // Viewer can read but not change anything.
        $this->actingAs($viewer)->get(route('websites.show', $website))->assertOk();
        $this->actingAs($viewer)->post(route('audit.crawl', $website))->assertForbidden();
        $this->actingAs($viewer)->patch(route('websites.update', $website), ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('websites.store'), ['name' => 'X', 'base_url' => 'https://x.com', 'language' => 'en', 'ownership_confirmed' => true])->assertForbidden();
        $this->actingAs($viewer)->post(route('team.invite'), ['email' => 'a@b.com', 'role' => 'viewer'])->assertForbidden();
        $this->actingAs($viewer)->get(route('integrations.search-console.redirect'))->assertForbidden();

        // Token cannot be reused.
        $this->actingAs($viewer)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');

        // Promote to editor: can work, still cannot manage members.
        $this->actingAs($owner)->patch(route('team.members.update', $viewer), ['role' => 'editor'])->assertSessionHasNoErrors();
        $this->fakeCrawler([$this->crawledPage('/')]);
        $this->actingAs($viewer)->post(route('audit.crawl', $website))->assertRedirect();
        $this->actingAs($viewer)->post(route('team.invite'), ['email' => 'a@b.com', 'role' => 'viewer'])->assertForbidden();
    }

    public function test_invitation_must_match_email_and_not_expire()
    {
        [$owner] = $this->userWithWebsite();
        $token = $this->inviteAndCaptureToken($owner, 'right@example.com', 'editor');

        $wrong = User::factory()->create(['email' => 'wrong@example.com']);
        $this->actingAs($wrong)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');
        $this->assertNull($wrong->workspaceRole($owner->current_workspace_id));

        WorkspaceInvitation::query()->update(['expires_at' => now()->subMinute()]);
        $right = User::factory()->create(['email' => 'right@example.com']);
        $this->actingAs($right)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');
    }

    public function test_last_owner_is_protected_and_admins_cannot_touch_owners()
    {
        [$owner, $website] = $this->userWithWebsite();
        $admin = User::factory()->create();
        $website->workspace->members()->attach($admin->id, ['role' => 'admin']);
        $admin->forceFill(['current_workspace_id' => $website->workspace_id])->save();

        $this->actingAs($owner)->delete(route('team.members.remove', $owner))->assertSessionHasErrors('member');
        $this->actingAs($admin)->patch(route('team.members.update', $owner), ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->delete(route('team.members.remove', $owner))->assertSessionHasErrors('member');
        $this->actingAs($admin)->post(route('team.invite'), ['email' => 'o@x.com', 'role' => 'owner'])->assertSessionHasErrors('role');

        // Admin leaves voluntarily; their current workspace falls back to their own.
        $this->actingAs($admin)->delete(route('team.members.remove', $admin))->assertRedirect(route('dashboard'));
        $this->assertNotSame($website->workspace_id, $admin->fresh()->current_workspace_id);
        $this->actingAs($admin)->get(route('websites.show', $website))->assertForbidden();
    }

    public function test_workspace_switching_and_team_page()
    {
        [$owner, $website] = $this->userWithWebsite();
        $other = User::factory()->create();
        $website->workspace->members()->attach($other->id, ['role' => 'editor']);

        $this->actingAs($other)->post(route('workspaces.switch', $website->workspace_id))->assertRedirect(route('dashboard'));
        $this->actingAs($other)->get(route('dashboard'))->assertRedirect(route('websites.show', $website));
        $this->actingAs($other)->get(route('team.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('team/Index')->has('members', 2)->where('canManage', false)->has('workspaces', 2));

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post(route('workspaces.switch', $website->workspace_id))->assertForbidden();
    }
}
