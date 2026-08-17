<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\User;
use App\Models\Workspace;

class DeploymentPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $user->can('view', $workspace);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Deployment $deployment): bool
    {
        return $user->can('view', $deployment->workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->can('manageDeployments', $workspace);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }

    /**
     * Determine whether the user can change the deployment stage.
     */
    public function changeStage(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }
}
