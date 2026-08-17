<?php

namespace App\Services;

use App\Models\AiToolCallTrace;
use App\Models\CopilotRequestLog;

class AiTraceRecorder
{
    /**
     * @var array<int, array{
     *   tool_name: string,
     *   duration_ms: int,
     *   status: string,
     *   metadata: array<string, mixed>
     * }>
     */
    private array $pendingToolTraces = [];

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private bool $ragUsed = false;

    private int $ragResultCount = 0;

    public function addUsage(array $response): void
    {
        $usage = $response['usage'] ?? null;

        if (! is_array($usage)) {
            return;
        }

        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;

        if (is_int($input)) {
            $this->inputTokens += $input;
        }

        if (is_int($output)) {
            $this->outputTokens += $output;
        }
    }

    public function recordRagUsage(int $resultCount): void
    {
        if ($resultCount > 0) {
            $this->ragUsed = true;
            $this->ragResultCount += $resultCount;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordToolCall(
        string $toolName,
        int $durationMs,
        string $status,
        array $metadata = [],
    ): void {
        $this->pendingToolTraces[] = [
            'tool_name' => $toolName,
            'duration_ms' => $durationMs,
            'status' => $status,
            'metadata' => $this->sanitizeMetadata($metadata),
        ];
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    public function ragUsed(): bool
    {
        return $this->ragUsed;
    }

    public function ragResultCount(): int
    {
        return $this->ragResultCount;
    }

    public function persist(
        CopilotContext $context,
        string $question,
        string $model,
        array $toolsUsed,
        int $startedAt,
        string $status,
        ?string $errorMessage,
        CopilotQuestionRedactor $questionRedactor,
        OpenAICostEstimator $costEstimator,
    ): CopilotRequestLog {
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $inputTokens = $this->inputTokens();
        $outputTokens = $this->outputTokens();

        $trace = CopilotRequestLog::query()->create([
            'workspace_id' => $context->workspace->id,
            'user_id' => $context->user->id,
            'customer_id' => $context->customer->id,
            'deployment_id' => $context->deployment->id,
            'model' => $model,
            'question' => $questionRedactor->redact($question),
            'tool_names' => array_values(array_unique($toolsUsed)),
            'input_tokens' => $inputTokens > 0 ? $inputTokens : null,
            'output_tokens' => $outputTokens > 0 ? $outputTokens : null,
            'rag_used' => $this->ragUsed(),
            'rag_result_count' => $this->ragResultCount(),
            'estimated_cost_usd' => $costEstimator->estimate($model, $inputTokens, $outputTokens),
            'latency_ms' => $latencyMs,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);

        foreach ($this->pendingToolTraces as $toolTrace) {
            AiToolCallTrace::query()->create([
                'copilot_request_log_id' => $trace->id,
                'workspace_id' => $context->workspace->id,
                'deployment_id' => $context->deployment->id,
                'tool_name' => $toolTrace['tool_name'],
                'duration_ms' => $toolTrace['duration_ms'],
                'status' => $toolTrace['status'],
                'metadata' => $toolTrace['metadata'],
            ]);
        }

        return $trace;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (preg_match('/(secret|password|token|api_key|authorization)/i', $key) === 1) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->sanitizeMetadata($value);
            }
        }

        return $safe;
    }
}
