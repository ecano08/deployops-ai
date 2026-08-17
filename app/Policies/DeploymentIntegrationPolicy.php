<?php

namespace App\Policies;

use App\Models\DeploymentIntegration;
use App\Models\User;
use App\Models\Workspace;

class DeploymentIntegrationPolicy
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
    public function view(User $user, DeploymentIntegration $integration): bool
    {
        return $user->can('view', $integration->workspace);
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
    public function update(User $user, DeploymentIntegration $integration): bool
    {
        return $user->can('manageDeployments', $integration->workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DeploymentIntegration $integration): bool
    {
        return $user->can('manageDeployments', $integration->workspace);
    }

    /**
     * Determine whether the user can test the integration connection.
     */
    public function test(User $user, DeploymentIntegration $integration): bool
    {
        return $user->can('manageDeployments', $integration->workspace);
    }

    /**
     * Determine whether the user can view integration activity.
     */
    public function viewActivities(User $user, DeploymentIntegration $integration): bool
    {
        return $user->can('view', $integration->workspace);
    }
}
