<?php

namespace App\Http\Controllers\Web;

use App\Domain\Workspace\TeamService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Policies\WorkspacePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function index(Request $request): Response
    {
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('view', $workspace);

        return Inertia::render('team/Index', [
            'workspace' => $workspace->only(['id', 'name', 'owner_user_id']),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role]),
            'invitations' => $workspace->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())
                ->with('inviter:id,name')->latest()->get(['id', 'email', 'role', 'invited_by', 'expires_at', 'created_at']),
            'myRole' => $request->user()->workspaceRole($workspace->id),
            'canManage' => $request->user()->can('manage', $workspace),
            'roles' => WorkspacePolicy::ROLES,
        ]);
    }

    public function updateWorkspace(Request $request): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);
        $workspace->update($request->validate(['name' => ['required', 'string', 'max:120']]));

        return back()->with('success', 'Workspace renamed.');
    }

    public function invite(Request $request): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(WorkspacePolicy::ROLES)],
        ]);
        [, $token] = $this->team->invite($workspace, $request->user(), $data['email'], $data['role']);

        return back()
            ->with('success', "Invitation created for {$data['email']}. Copy the link below and send it to them yourself.")
            ->with('flash_data', ['invite_link' => route('invitations.show', $token)]);
    }

    public function revoke(Request $request, WorkspaceInvitation $invitation): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);
        abort_unless($invitation->workspace_id === $workspace->id, 404);
        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }

    public function updateMember(Request $request, User $member): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $this->authorize('manage', $workspace);
        $data = $request->validate(['role' => ['required', Rule::in(WorkspacePolicy::ROLES)]]);
        $this->team->changeRole($workspace, $request->user(), $member, $data['role']);

        return back()->with('success', 'Role updated.');
    }

    public function removeMember(Request $request, User $member): RedirectResponse
    {
        $workspace = $request->user()->activeWorkspace();
        $self = $member->id === $request->user()->id;
        if (! $self) {
            $this->authorize('manage', $workspace);
        }
        $this->team->remove($workspace, $request->user(), $member);

        return $self
            ? redirect()->route('dashboard')->with('success', "You left {$workspace->name}.")
            : back()->with('success', 'Member removed.');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);
        $request->user()->forceFill(['current_workspace_id' => $workspace->id])->save();
        $request->session()->forget('current_website_id');

        return redirect()->route('dashboard');
    }

    /** Public landing page for an invitation link. */
    public function showInvitation(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->team->findByToken($token);
        if (! $request->user()) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return Inertia::render('team/Invitation', [
            'token' => $token,
            'invitation' => $invitation ? [
                'workspace' => $invitation->workspace->name,
                'role' => $invitation->role,
                'email' => $invitation->email,
                'inviter' => $invitation->inviter?->name,
                'valid' => $invitation->isPending(),
            ] : null,
            'loggedInAs' => $request->user()?->email,
        ]);
    }

    public function acceptInvitation(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->team->findByToken($token);
        abort_unless($invitation, 404);
        $this->team->accept($invitation, $request->user());
        $request->session()->forget('current_website_id');

        return redirect()->route('dashboard')->with('success', "You joined {$invitation->workspace->name}.");
    }
}
