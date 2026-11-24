<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_new_users_get_a_workspace_and_are_sent_to_onboarding()
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->fresh()->current_workspace_id);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('websites.create'));
        $this->actingAs($user)->get(route('websites.create'))->assertOk();
    }

    public function test_dashboard_redirects_to_first_website_overview()
    {
        [$user, $website] = $this->userWithWebsite();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('websites.show', $website));
        $this->actingAs($user)->get(route('websites.show', $website))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard')->has('overview.next_actions'));
    }
}
