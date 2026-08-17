<?php

namespace App\Policies;

use App\Enums\AiActionStatus;
use App\Models\AiProposedAction;
use App\Models\Deployment;
use App\Models\User;

class AiProposedActionPolicy
{
    public function viewAny(User $user, Deployment $deployment): bool
    {
        return $user->can('view', $deployment);
    }

    public function view(User $user, AiProposedAction $aiProposedAction): bool
    {
        return $user->can('view', $aiProposedAction->deployment);
    }

    public function propose(User $user, Deployment $deployment): bool
    {
        return $user->can('manageDeployments', $deployment->workspace);
    }

    public function approve(User $user, AiProposedAction $aiProposedAction): bool
    {
        if ($aiProposedAction->requested_by === $user->id) {
            return false;
        }

        return $user->can('approveAiActions', $aiProposedAction->workspace);
    }

    public function execute(User $user, AiProposedAction $aiProposedAction): bool
    {
        if ($aiProposedAction->status !== AiActionStatus::Approved) {
            return false;
        }

        return $user->can('approveAiActions', $aiProposedAction->workspace);
    }
}
