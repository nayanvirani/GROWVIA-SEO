<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

/**
 * Roles: owner > admin > editor > viewer.
 * owner/admin manage members, integrations and websites; editors do SEO work; viewers read only.
 */
class WorkspacePolicy
{
    public const ROLES = ['owner', 'admin', 'editor', 'viewer'];

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace->id);
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return in_array($user->workspaceRole($workspace->id), ['owner', 'admin'], true);
    }

    public function createWebsite(User $user, Workspace $workspace): bool
    {
        return $this->manage($user, $workspace);
    }
}
