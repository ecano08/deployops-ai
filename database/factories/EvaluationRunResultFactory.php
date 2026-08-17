<?php

namespace Database\Factories;

use App\Models\EvaluationCase;
use App\Models\EvaluationRun;
use App\Models\EvaluationRunResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationRunResult>
 */
class EvaluationRunResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passed' => true,
            'latency_ms' => 120,
            'tools_used' => ['get_deployment'],
            'sources_used' => [],
            'answer' => fake()->sentence(),
            'error_message' => null,
            'metrics' => [
                'response_success' => true,
                'expected_tool_usage' => true,
                'expected_source_usage' => null,
                'groundedness' => true,
                'latency_acceptable' => true,
            ],
        ];
    }

    public function forRun(EvaluationRun $run, EvaluationCase $case): static
    {
        return $this->state(fn (): array => [
            'evaluation_run_id' => $run->id,
            'evaluation_case_id' => $case->id,
        ]);
    }
}
