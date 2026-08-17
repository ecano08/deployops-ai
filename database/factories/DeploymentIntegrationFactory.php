<?php

namespace Database\Factories;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeploymentIntegration>
 */
class DeploymentIntegrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'type' => fake()->randomElement(IntegrationType::cases()),
            'name' => fake()->words(2, true),
            'base_url' => 'https://api.example.com',
            'endpoint' => '/health',
            'status' => IntegrationStatus::Disconnected,
            'config' => ['timeout' => 5],
            'secrets' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (DeploymentIntegration $integration): void {
            if ($integration->deployment_id === null) {
                return;
            }

            $deployment = Deployment::query()->find($integration->deployment_id);

            if ($deployment !== null) {
                $integration->workspace_id = $deployment->workspace_id;
            }
        });
    }

    public function forDeployment(Deployment $deployment): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'deployment_id' => $deployment->id,
        ]);
    }

    public function restApi(): static
    {
        return $this->state(fn (): array => [
            'type' => IntegrationType::RestApi,
            'base_url' => 'https://api.example.com',
            'endpoint' => '/health',
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn (): array => [
            'type' => IntegrationType::Webhook,
            'base_url' => null,
            'endpoint' => null,
            'secrets' => ['webhook_secret' => Str::random(32)],
        ]);
    }

    public function withApiKey(string $apiKey = 'test-api-key'): static
    {
        return $this->state(fn (): array => [
            'secrets' => ['api_key' => $apiKey],
        ]);
    }

    public function status(IntegrationStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }
}
