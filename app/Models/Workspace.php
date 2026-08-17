<?php

namespace App\Models;

use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'owner_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceMember>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(WorkspaceMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<WorkspaceInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function addMemberWithRole(User $user, WorkspaceRole $role): User
    {
        $this->members()->attach($user->id, [
            'role' => $role->value,
        ]);

        $this->invitations()
            ->where('email', $user->email)
            ->where('status', WorkspaceInvitationStatus::Pending)
            ->update([
                'status' => WorkspaceInvitationStatus::Accepted,
                'accepted_at' => now(),
            ]);

        return $this->members()->whereKey($user->id)->firstOrFail();
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function roleFor(User $user): ?WorkspaceRole
    {
        return $user->roleIn($this);
    }

    public function includesUser(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        if ($this->relationLoaded('members')) {
            return $this->members->contains('id', $user->id);
        }

        return $this->members()->whereKey($user->id)->exists();
    }

    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
