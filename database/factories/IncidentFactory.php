<?php

namespace Database\Factories;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Deployment;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'severity' => IncidentSeverity::Medium,
            'status' => IncidentStatus::Open,
            'source' => IncidentSource::Manual,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
        ];
    }

    public function forDeployment(Deployment $deployment, ?User $creator = null): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'created_by' => $creator?->id,
        ]);
    }
}
