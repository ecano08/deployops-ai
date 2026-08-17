<?php

namespace Database\Factories;

use App\Models\AiToolCallTrace;
use App\Models\CopilotRequestLog;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolCallTrace>
 */
class AiToolCallTraceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool_name' => 'get_deployment',
            'duration_ms' => fake()->numberBetween(1, 200),
            'status' => 'success',
            'metadata' => ['result_count' => 1],
        ];
    }

    public function forTrace(CopilotRequestLog $trace): static
    {
        return $this->state(fn (): array => [
            'copilot_request_log_id' => $trace->id,
            'workspace_id' => $trace->workspace_id,
            'deployment_id' => $trace->deployment_id,
        ]);
    }

    public function forDeployment(Deployment $deployment): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'deployment_id' => $deployment->id,
        ]);
    }
}
