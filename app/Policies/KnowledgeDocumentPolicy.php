<?php

namespace App\Policies;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\Workspace;

class KnowledgeDocumentPolicy
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
    public function view(User $user, KnowledgeDocument $document): bool
    {
        return $user->can('view', $document->workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->can('manageDeployments', $workspace);
    }

    /**
     * Determine whether the user can activate the model.
     */
    public function activate(User $user, KnowledgeDocument $document): bool
    {
        return $user->can('manageDeployments', $document->workspace);
    }

    /**
     * Determine whether the user can archive the model.
     */
    public function archive(User $user, KnowledgeDocument $document): bool
    {
        return $user->can('manageDeployments', $document->workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KnowledgeDocument $document): bool
    {
        return $user->can('manageDeployments', $document->workspace);
    }
}
