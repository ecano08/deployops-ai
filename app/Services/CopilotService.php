<?php

namespace App\Services;

use App\Exceptions\CopilotException;
use App\Models\CopilotRequestLog;
use Illuminate\Auth\Access\AuthorizationException;
use JsonException;

class CopilotService
{
    private const int MAX_TOOL_ROUNDS = 5;

    public function __construct(
        private OpenAIResponsesClient $openAI,
        private CopilotToolExecutor $toolExecutor,
        private CopilotQuestionRedactor $questionRedactor,
    ) {}

    /**
     * @return array{answer: string, tools_used: array<int, string>}
     */
    public function ask(CopilotContext $context, string $question): array
    {
        $telemetry = $this->askWithTelemetry($context, $question, persistLog: true);

        if ($telemetry['error'] !== null) {
            throw new CopilotException(
                $telemetry['error'],
                $telemetry['status_code'] ?? 502,
            );
        }

        return [
            'answer' => $telemetry['answer'] ?? '',
            'tools_used' => $telemetry['tools_used'],
        ];
    }

    /**
     * @return array{
     *   answer: string|null,
     *   tools_used: array<int, string>,
     *   sources_used: array<int, string>,
     *   latency_ms: int,
     *   error: string|null,
     *   status_code: int|null
     * }
     */
    public function askWithTelemetry(CopilotContext $context, string $question, bool $persistLog = true): array
    {
        $startedAt = hrtime(true);
        $toolsUsed = [];
        $sourcesUsed = [];
        $model = (string) config('services.openai.model');

        try {
            $instructions = $this->instructions($context);
            $tools = $this->toolExecutor->definitions();
            $input = $question;
            $previousResponseId = null;

            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $response = $this->openAI->create($instructions, $input, $tools, $previousResponseId);
                $previousResponseId = $this->openAI->responseId($response);

                $functionCalls = $this->openAI->extractFunctionCalls($response);

                if ($functionCalls === []) {
                    $answer = $this->openAI->extractOutputText($response);

                    if ($answer === null || $answer === '') {
                        throw new CopilotException('The AI service returned an empty response.', 502);
                    }

                    $latencyMs = $this->latencyMs($startedAt);

                    if ($persistLog) {
                        $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'success', null);
                    }

                    return [
                        'answer' => $answer,
                        'tools_used' => array_values(array_unique($toolsUsed)),
                        'sources_used' => array_values(array_unique($sourcesUsed)),
                        'latency_ms' => $latencyMs,
                        'error' => null,
                        'status_code' => null,
                    ];
                }

                $toolOutputs = [];

                foreach ($functionCalls as $call) {
                    $toolsUsed[] = $call['name'];

                    try {
                        $arguments = json_decode($call['arguments'], true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $arguments = [];
                    }

                    if (! is_array($arguments)) {
                        $arguments = [];
                    }

                    try {
                        $result = $this->toolExecutor->validateAndExecute($context, $call['name'], $arguments);
                    } catch (AuthorizationException $exception) {
                        $result = [
                            'error' => $exception->getMessage() ?: 'Not authorized to access this resource.',
                        ];
                    }

                    $sourcesUsed = array_merge($sourcesUsed, $this->extractSourcesFromToolResult($call['name'], $result));

                    $toolOutputs[] = [
                        'type' => 'function_call_output',
                        'call_id' => $call['call_id'],
                        'output' => json_encode($result, JSON_THROW_ON_ERROR),
                    ];
                }

                $input = $toolOutputs;
            }

            throw new CopilotException('The copilot exceeded the maximum number of tool calls.', 502);
        } catch (CopilotException $exception) {
            if ($persistLog) {
                $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'failure', $exception->getMessage());
            }

            return [
                'answer' => null,
                'tools_used' => array_values(array_unique($toolsUsed)),
                'sources_used' => array_values(array_unique($sourcesUsed)),
                'latency_ms' => $this->latencyMs($startedAt),
                'error' => $exception->getMessage(),
                'status_code' => $exception->statusCode,
            ];
        } catch (\Throwable $exception) {
            if ($persistLog) {
                $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'failure', 'An unexpected copilot error occurred.');
            }

            return [
                'answer' => null,
                'tools_used' => array_values(array_unique($toolsUsed)),
                'sources_used' => array_values(array_unique($sourcesUsed)),
                'latency_ms' => $this->latencyMs($startedAt),
                'error' => 'An unexpected copilot error occurred.',
                'status_code' => 503,
            ];
        }
    }

    private function instructions(CopilotContext $context): string
    {
        return sprintf(
            'You are DeployOps AI Copilot, a read-only assistant for deployment operations. '.
            'You are scoped to workspace "%s", customer "%s", and deployment "%s". '.
            'Use the provided tools to fetch factual data before answering. '.
            'For questions about uploaded documentation, runbooks, or guides, call search_knowledge first. '.
            'Only cite knowledge that appears in search_knowledge results and include source filenames. '.
            'If search_knowledge returns no relevant results, say you do not have that information in the knowledge base. '.
            'Never invent deployment, customer, integration, or documentation details. '.
            'Only reference integration IDs returned by list_deployment_integrations. '.
            'To change deployment stage, use propose_update_deployment_stage which creates a pending action for human approval. '.
            'Never claim a deployment stage was changed unless an action was proposed and approved. '.
            'Do not request or expose secrets such as API keys or webhook secrets.',
            $context->workspace->name,
            $context->customer->name,
            $context->deployment->name,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function extractSourcesFromToolResult(string $toolName, array $result): array
    {
        if ($toolName !== 'search_knowledge' || ! isset($result['results']) || ! is_array($result['results'])) {
            return [];
        }

        $sources = [];

        foreach ($result['results'] as $item) {
            if (is_array($item) && isset($item['source_filename']) && is_string($item['source_filename'])) {
                $sources[] = $item['source_filename'];
            }
        }

        return $sources;
    }

    private function latencyMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * @param  array<int, string>  $toolsUsed
     */
    private function logRequest(
        CopilotContext $context,
        string $question,
        string $model,
        array $toolsUsed,
        int $startedAt,
        string $status,
        ?string $errorMessage,
    ): void {
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        CopilotRequestLog::query()->create([
            'workspace_id' => $context->workspace->id,
            'user_id' => $context->user->id,
            'customer_id' => $context->customer->id,
            'deployment_id' => $context->deployment->id,
            'model' => $model,
            'question' => $this->questionRedactor->redact($question),
            'tool_names' => array_values(array_unique($toolsUsed)),
            'latency_ms' => $latencyMs,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }
}
