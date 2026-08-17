<?php

namespace Database\Factories;

use App\Enums\IntegrationActivityType;
use App\Models\DeploymentIntegration;
use App\Models\IntegrationActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationActivity>
 */
class IntegrationActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deployment_integration_id' => DeploymentIntegration::factory(),
            'type' => IntegrationActivityType::TestConnection,
            'status' => 'success',
            'metadata' => ['http_status' => 200],
            'message' => fake()->optional()->sentence(),
        ];
    }

    public function forIntegration(DeploymentIntegration $integration): static
    {
        return $this->state(fn (): array => [
            'deployment_integration_id' => $integration->id,
        ]);
    }

    public function failure(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failure',
            'type' => IntegrationActivityType::Error,
            'message' => 'Connection failed.',
        ]);
    }
}
