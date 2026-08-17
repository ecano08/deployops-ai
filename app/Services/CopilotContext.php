<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deployment;
use App\Models\User;
use App\Models\Workspace;

readonly class CopilotContext
{
    /**
     * @param  array<int, string>  $userQuestionHistory
     */
    public function __construct(
        public User $user,
        public Workspace $workspace,
        public Customer $customer,
        public Deployment $deployment,
        public ?string $currentQuestion = null,
        public array $userQuestionHistory = [],
    ) {}
}
