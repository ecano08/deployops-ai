<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->memberRole($user, $workspace) !== null
            || $workspace->owner_id === $user->id;
    }

    /**
     * Determine whether the user can list workspace members.
     */
    public function viewMembers(User $user, Workspace $workspace): bool
    {
        return $this->view($user, $workspace);
    }

    /**
     * Determine whether the user can access operational workspace data.
     */
    public function operate(User $user, Workspace $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return $this->memberRole($user, $workspace)?->canOperate() ?? false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Workspace $workspace): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return false;
    }

    /**
     * Determine whether the user can add workspace members.
     */
    public function addMember(User $user, Workspace $workspace): bool
    {
        return $this->manageMembers($user, $workspace);
    }

    /**
     * Determine whether the user can change a member's role.
     */
    public function updateMember(User $user, Workspace $workspace, User $member): bool
    {
        if (! $this->manageMembers($user, $workspace)) {
            return false;
        }

        return ! $this->isProtectedOwner($workspace, $member);
    }

    /**
     * Determine whether the user can remove a workspace member.
     */
    public function removeMember(User $user, Workspace $workspace, User $member): bool
    {
        if (! $this->manageMembers($user, $workspace)) {
            return false;
        }

        return ! $this->isProtectedOwner($workspace, $member);
    }

    /**
     * Determine whether the user can manage workspace members and roles.
     */
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return $this->memberRole($user, $workspace)?->canManageMembers() ?? false;
    }

    private function isProtectedOwner(Workspace $workspace, User $member): bool
    {
        if ($workspace->owner_id === $member->id) {
            return true;
        }

        return $this->memberRole($member, $workspace) === WorkspaceRole::Owner;
    }

    private function memberRole(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return $user->roleIn($workspace);
    }
}
