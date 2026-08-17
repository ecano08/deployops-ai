<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\EvaluationDataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationDataset>
 */
class EvaluationDatasetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forDeployment(Deployment $deployment): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
        ]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EvaluationDataset $dataset): void {
            if ($dataset->deployment_id === null) {
                return;
            }

            $deployment = Deployment::query()->find($dataset->deployment_id);

            if ($deployment !== null) {
                $dataset->workspace_id = $deployment->workspace_id;
                $dataset->customer_id = $deployment->customer_id;
            }
        });
    }
}
