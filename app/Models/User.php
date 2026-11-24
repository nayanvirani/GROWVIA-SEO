<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'email_digest',
        'email_reminders',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'email_digest' => 'boolean',
            'email_reminders' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every user gets a personal workspace so all data has a tenant boundary from day one.
        static::created(function (User $user) {
            $workspace = Workspace::create([
                'name' => $user->name."'s workspace",
                'owner_user_id' => $user->id,
            ]);
            $workspace->members()->attach($user->id, ['role' => 'owner']);
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        });
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /** Current workspace, falling back to another membership if access was removed. */
    public function activeWorkspace(): ?Workspace
    {
        $workspace = $this->current_workspace_id ? $this->workspaces()->whereKey($this->current_workspace_id)->first() : null;
        if (! $workspace && ($workspace = $this->workspaces()->first())) {
            $this->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        return $workspace;
    }

    public function belongsToWorkspace(string $workspaceId): bool
    {
        return $this->workspaces()->whereKey($workspaceId)->exists();
    }

    public function workspaceRole(string $workspaceId): ?string
    {
        return $this->workspaces()->whereKey($workspaceId)->first()?->pivot?->role;
    }
}
