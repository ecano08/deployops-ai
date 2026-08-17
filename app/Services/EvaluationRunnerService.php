<?php

namespace App\Services;

use App\Enums\EvaluationRunStatus;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\User;

class EvaluationRunnerService
{
    public function __construct(
        private CopilotService $copilot,
        private EvaluationMetricsCalculator $metricsCalculator,
    ) {}

    public function run(EvaluationDataset $dataset, User $runner): EvaluationRun
    {
        $dataset->loadMissing('cases', 'deployment', 'customer', 'workspace');

        $run = EvaluationRun::query()->create([
            'evaluation_dataset_id' => $dataset->id,
            'workspace_id' => $dataset->workspace_id,
            'customer_id' => $dataset->customer_id,
            'deployment_id' => $dataset->deployment_id,
            'run_by' => $runner->id,
            'status' => EvaluationRunStatus::Running,
            'started_at' => now(),
        ]);

        $context = new CopilotContext(
            user: $runner,
            workspace: $dataset->workspace,
            customer: $dataset->customer,
            deployment: $dataset->deployment,
        );

        $passedCases = 0;
        $failedCases = 0;
        $totalLatency = 0;

        try {
            foreach ($dataset->cases as $case) {
                $telemetry = $this->copilot->askWithTelemetry($context, $case->input, persistLog: false);

                $metrics = $this->metricsCalculator->calculate(
                    evaluationCase: $case,
                    answer: $telemetry['answer'],
                    errorMessage: $telemetry['error'],
                    toolsUsed: $telemetry['tools_used'],
                    sourcesUsed: $telemetry['sources_used'],
                    latencyMs: $telemetry['latency_ms'],
                );

                if ($metrics['passed']) {
                    $passedCases++;
                } else {
                    $failedCases++;
                }

                $totalLatency += $telemetry['latency_ms'];

                $run->results()->create([
                    'evaluation_case_id' => $case->id,
                    'passed' => $metrics['passed'],
                    'latency_ms' => $telemetry['latency_ms'],
                    'tools_used' => $telemetry['tools_used'],
                    'sources_used' => $telemetry['sources_used'],
                    'answer' => $telemetry['answer'],
                    'error_message' => $telemetry['error'],
                    'metrics' => $metrics,
                ]);
            }

            $totalCases = $dataset->cases->count();
            $aggregateMetrics = [
                'total_cases' => $totalCases,
                'passed_cases' => $passedCases,
                'failed_cases' => $failedCases,
                'pass_rate' => $totalCases > 0 ? round($passedCases / $totalCases, 4) : 0.0,
                'average_latency_ms' => $totalCases > 0 ? (int) round($totalLatency / $totalCases) : 0,
            ];

            $run->update([
                'status' => EvaluationRunStatus::Completed,
                'metrics' => $aggregateMetrics,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => EvaluationRunStatus::Failed,
                'metrics' => [
                    'error' => 'Evaluation run failed unexpectedly.',
                ],
                'completed_at' => now(),
            ]);

            throw $exception;
        }

        return $run->fresh(['results.evaluationCase']);
    }
}
