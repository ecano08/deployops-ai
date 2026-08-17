<?php

namespace App\Policies;

use App\Models\CopilotRequestLog;
use App\Models\Deployment;
use App\Models\User;

class CopilotRequestLogPolicy
{
    public function viewAny(User $user, Deployment $deployment): bool
    {
        return $user->can('view', $deployment);
    }

    public function view(User $user, CopilotRequestLog $copilotRequestLog): bool
    {
        return $user->can('view', $copilotRequestLog->deployment)
            && $copilotRequestLog->workspace_id === $copilotRequestLog->deployment->workspace_id;
    }
}
