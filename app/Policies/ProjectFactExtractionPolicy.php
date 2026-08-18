<?php

namespace App\Policies;

use App\Models\ProjectFactExtraction;
use App\Models\User;

class ProjectFactExtractionPolicy
{
    public function view(User $user, ProjectFactExtraction $projectFactExtraction): bool
    {
        return $user->can('manageDeployments', $projectFactExtraction->workspace);
    }
}
