<?php

namespace App\Domain\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public const INVITE_DAYS = 7;

    public function invite(Workspace $workspace, User $inviter, string $email, string $role): WorkspaceInvitation
    {
        $email = Str::lower(trim($email));
        if ($role === 'owner' && $inviter->workspaceRole($workspace->id) !== 'owner') {
            throw ValidationException::withMessages(['role' => 'Only an owner can invite another owner.']);
        }
        if ($workspace->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already a member.']);
        }

        // Replace any earlier pending invitation for the same address.
        $workspace->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(48);
        $invitation = $workspace->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(self::INVITE_DAYS),
        ]);
        Notification::route('mail', $email)->notify(new WorkspaceInvitationSent($invitation, $token));

        return $invitation;
    }

    public function findByToken(string $token): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::with('workspace', 'inviter:id,name')->where('token_hash', hash('sha256', $token))->first();
    }

    public function accept(WorkspaceInvitation $invitation, User $user): void
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired or was already used.']);
        }
        if (Str::lower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['invitation' => "This invitation was sent to {$invitation->email}. Log in with that address to accept it."]);
        }

        DB::transaction(function () use ($invitation, $user) {
            $invitation->workspace->members()->syncWithoutDetaching([$user->id => ['role' => $invitation->role]]);
            $invitation->update(['accepted_at' => now()]);
            $user->forceFill(['current_workspace_id' => $invitation->workspace_id])->save();
        });
    }

    public function changeRole(Workspace $workspace, User $actor, User $member, string $role): void
    {
        $current = $member->workspaceRole($workspace->id) ?? throw ValidationException::withMessages(['member' => 'Not a member.']);
        if (($current === 'owner' || $role === 'owner') && $actor->workspaceRole($workspace->id) !== 'owner') {
            throw ValidationException::withMessages(['role' => 'Only an owner can grant or remove the owner role.']);
        }
        if ($current === 'owner' && $role !== 'owner') {
            $this->ensureAnotherOwner($workspace, $member);
        }
        $workspace->members()->updateExistingPivot($member->id, ['role' => $role]);
    }

    public function remove(Workspace $workspace, User $actor, User $member): void
    {
        $role = $member->workspaceRole($workspace->id) ?? throw ValidationException::withMessages(['member' => 'Not a member.']);
        if ($role === 'owner' && $actor->workspaceRole($workspace->id) !== 'owner') {
            throw ValidationException::withMessages(['member' => 'Only an owner can remove another owner.']);
        }
        if ($role === 'owner') {
            $this->ensureAnotherOwner($workspace, $member);
        }

        DB::transaction(function () use ($workspace, $member) {
            $workspace->members()->detach($member->id);
            if ($member->current_workspace_id === $workspace->id) {
                $member->forceFill(['current_workspace_id' => $member->workspaces()->value('workspaces.id')])->save();
            }
        });
    }

    private function ensureAnotherOwner(Workspace $workspace, User $member): void
    {
        $others = $workspace->members()->wherePivot('role', 'owner')->where('users.id', '!=', $member->id)->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['member' => 'A workspace needs at least one owner. Make someone else owner first.']);
        }
    }
}
