<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;

class CustomerPolicy
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
    public function view(User $user, Customer $customer): bool
    {
        return $user->can('view', $customer->workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $user->can('manageCustomers', $workspace);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->can('manageCustomers', $customer->workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('manageCustomers', $customer->workspace);
    }
}
