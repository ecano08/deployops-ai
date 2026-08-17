<?php

namespace Database\Factories;

use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Models\AiProposedAction;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProposedAction>
 */
class AiProposedActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_type' => AiActionType::UpdateDeploymentStage,
            'payload' => ['stage' => 'build'],
            'status' => AiActionStatus::Pending,
        ];
    }

    public function forDeployment(Deployment $deployment, User $requester): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'requested_by' => $requester->id,
        ]);
    }
}
