<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\EvaluationDataset;
use App\Models\User;
use App\Models\Workspace;

class EvaluationDatasetPolicy
{
    public function viewAny(User $user, Workspace $workspace, Deployment $deployment): bool
    {
        return $user->can('view', $deployment);
    }

    public function view(User $user, EvaluationDataset $evaluationDataset): bool
    {
        return $user->can('view', $evaluationDataset->deployment);
    }

    public function create(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }

    public function update(User $user, EvaluationDataset $evaluationDataset): bool
    {
        return $user->can('manageDeployments', $evaluationDataset->workspace);
    }

    public function delete(User $user, EvaluationDataset $evaluationDataset): bool
    {
        return $user->can('manageDeployments', $evaluationDataset->workspace);
    }

    public function run(User $user, EvaluationDataset $evaluationDataset): bool
    {
        return $user->can('manageDeployments', $evaluationDataset->workspace);
    }
}
