<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function view(User $user, Website $website): bool
    {
        return $user->belongsToWorkspace($website->workspace_id);
    }

    public function update(User $user, Website $website): bool
    {
        return in_array($user->workspaceRole($website->workspace_id), ['owner', 'admin', 'editor'], true);
    }

    public function delete(User $user, Website $website): bool
    {
        return in_array($user->workspaceRole($website->workspace_id), ['owner', 'admin'], true);
    }
}
