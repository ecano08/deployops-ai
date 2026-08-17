<?php

namespace Database\Factories;

use App\Enums\EvaluationRunStatus;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationRun>
 */
class EvaluationRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => EvaluationRunStatus::Completed,
            'metrics' => [
                'total_cases' => 1,
                'passed_cases' => 1,
                'failed_cases' => 0,
                'pass_rate' => 1.0,
                'average_latency_ms' => 100,
            ],
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }

    public function forDataset(EvaluationDataset $dataset, User $runner): static
    {
        return $this->state(fn (): array => [
            'evaluation_dataset_id' => $dataset->id,
            'workspace_id' => $dataset->workspace_id,
            'customer_id' => $dataset->customer_id,
            'deployment_id' => $dataset->deployment_id,
            'run_by' => $runner->id,
        ]);
    }
}
