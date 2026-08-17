<?php

namespace Database\Factories;

use App\Models\EvaluationCase;
use App\Models\EvaluationDataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationCase>
 */
class EvaluationCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'input' => fake()->sentence().'?',
            'expected_behavior' => fake()->sentence(),
            'expected_tools' => null,
            'expected_sources' => null,
        ];
    }

    public function forDataset(EvaluationDataset $dataset): static
    {
        return $this->state(fn (): array => [
            'evaluation_dataset_id' => $dataset->id,
        ]);
    }
}
