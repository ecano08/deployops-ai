<?php

namespace App\Policies;

use App\Enums\ProjectFactStatus;
use App\Models\Deployment;
use App\Models\ProjectFact;
use App\Models\User;

class ProjectFactPolicy
{
    public function viewAny(User $user, Deployment $deployment): bool
    {
        return $user->can('view', $deployment);
    }

    public function view(User $user, ProjectFact $projectFact): bool
    {
        return $user->can('view', $projectFact->deployment);
    }

    public function create(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }

    public function update(User $user, ProjectFact $projectFact): bool
    {
        if ($projectFact->status !== ProjectFactStatus::Proposed) {
            return false;
        }

        return $user->can('manageDeployments', $projectFact->workspace);
    }

    public function verify(User $user, ProjectFact $projectFact): bool
    {
        if ($projectFact->status !== ProjectFactStatus::Proposed) {
            return false;
        }

        return $user->can('manageDeployments', $projectFact->workspace);
    }

    public function reject(User $user, ProjectFact $projectFact): bool
    {
        if ($projectFact->status !== ProjectFactStatus::Proposed) {
            return false;
        }

        return $user->can('manageDeployments', $projectFact->workspace);
    }

    public function extract(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }
}
