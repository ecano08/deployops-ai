<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user, Deployment $deployment): bool
    {
        return $user->can('view', $deployment);
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('view', $incident->deployment)
            && $incident->workspace_id === $incident->deployment->workspace_id;
    }

    public function create(User $user, Deployment $deployment): bool
    {
        return $user->can('operate', $deployment->workspace);
    }

    public function update(User $user, Incident $incident): bool
    {
        return $user->can('operate', $incident->workspace);
    }
}
