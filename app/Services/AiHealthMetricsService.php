<?php

namespace App\Services;

use App\Models\AiToolCallTrace;
use App\Models\CopilotRequestLog;
use App\Models\Deployment;
use Illuminate\Support\Carbon;

class AiHealthMetricsService
{
    /**
     * @return array{
     *   request_count: int,
     *   failure_count: int,
     *   failure_rate: float,
     *   average_latency_ms: float,
     *   total_input_tokens: int,
     *   total_output_tokens: int,
     *   estimated_cost_usd: float,
     *   tool_failure_count: int,
     *   rag_request_count: int
     * }
     */
    public function summarize(Deployment $deployment, ?int $days = 7): array
    {
        $since = Carbon::now()->subDays($days ?? 7);

        $traces = CopilotRequestLog::query()
            ->where('deployment_id', $deployment->id)
            ->where('workspace_id', $deployment->workspace_id)
            ->where('created_at', '>=', $since);

        $requestCount = (clone $traces)->count();
        $failureCount = (clone $traces)->where('status', 'failure')->count();
        $averageLatency = (float) ((clone $traces)->avg('latency_ms') ?? 0);
        $totalInputTokens = (int) ((clone $traces)->sum('input_tokens') ?? 0);
        $totalOutputTokens = (int) ((clone $traces)->sum('output_tokens') ?? 0);
        $estimatedCost = (float) ((clone $traces)->sum('estimated_cost_usd') ?? 0);
        $ragRequestCount = (clone $traces)->where('rag_used', true)->count();

        $toolFailureCount = AiToolCallTrace::query()
            ->where('deployment_id', $deployment->id)
            ->where('workspace_id', $deployment->workspace_id)
            ->where('status', 'failure')
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'request_count' => $requestCount,
            'failure_count' => $failureCount,
            'failure_rate' => $requestCount > 0 ? round($failureCount / $requestCount, 4) : 0.0,
            'average_latency_ms' => round($averageLatency, 2),
            'total_input_tokens' => $totalInputTokens,
            'total_output_tokens' => $totalOutputTokens,
            'estimated_cost_usd' => round($estimatedCost, 6),
            'tool_failure_count' => $toolFailureCount,
            'rag_request_count' => $ragRequestCount,
        ];
    }
}
