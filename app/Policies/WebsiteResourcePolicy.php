<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy for every website-owned record. Authorization is derived from the record's
 * workspace membership, never from what the UI chose to show.
 */
class WebsiteResourcePolicy
{
    public function view(User $user, Model $resource): bool
    {
        return $user->belongsToWorkspace($resource->workspace_id);
    }

    public function update(User $user, Model $resource): bool
    {
        return in_array($user->workspaceRole($resource->workspace_id), ['owner', 'admin', 'editor'], true);
    }

    public function delete(User $user, Model $resource): bool
    {
        return $this->update($user, $resource);
    }
}
