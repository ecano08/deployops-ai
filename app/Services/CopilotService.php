<?php

namespace App\Services;

use App\Exceptions\CopilotException;
use App\Models\CopilotRequestLog;
use Illuminate\Auth\Access\AuthorizationException;
use JsonException;

class CopilotService
{
    private const int MAX_TOOL_ROUNDS = 5;

    private const int MAX_HISTORY_TURNS = 6;

    public function __construct(
        private OpenAIResponsesClient $openAI,
        private CopilotToolExecutor $toolExecutor,
        private CopilotQuestionRedactor $questionRedactor,
        private OpenAICostEstimator $costEstimator,
        private IncidentService $incidentService,
        private CopilotKnowledgeGroundingAdvisor $groundingAdvisor,
    ) {}

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return array{answer: string, tools_used: array<int, string>}
     */
    public function ask(CopilotContext $context, string $question, array $history = []): array
    {
        $telemetry = $this->askWithTelemetry($context, $question, $history, persistLog: true);

        if ($telemetry['error'] !== null) {
            throw new CopilotException(
                $telemetry['error'],
                $telemetry['status_code'] ?? 502,
                isset($telemetry['reference']) ? (string) $telemetry['reference'] : null,
            );
        }

        return [
            'answer' => $telemetry['answer'] ?? '',
            'tools_used' => $telemetry['tools_used'],
        ];
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return array{
     *   answer: string|null,
     *   tools_used: array<int, string>,
     *   sources_used: array<int, string>,
     *   latency_ms: int,
     *   error: string|null,
     *   status_code: int|null,
     *   reference: int|null
     * }
     */
    public function askWithTelemetry(
        CopilotContext $context,
        string $question,
        array $history = [],
        bool $persistLog = true,
    ): array {
        $startedAt = hrtime(true);
        $toolsUsed = [];
        $sourcesUsed = [];
        $model = (string) config('services.openai.model');
        $traceRecorder = new AiTraceRecorder;
        $history = $this->normalizeHistory($history);
        $executionContext = $this->executionContext($context, $question, $history);

        try {
            $instructions = $this->instructions($context, $history, $question);
            $tools = $this->toolExecutor->definitions();
            $input = $this->buildInput($history, $question);
            $conversationItems = null;

            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $response = $this->openAI->create($instructions, $input, $tools);
                $traceRecorder->addUsage($response);

                $functionCalls = $this->openAI->extractFunctionCalls($response);

                if ($functionCalls === []) {
                    $answer = $this->openAI->extractOutputText($response);

                    if ($answer === null || $answer === '') {
                        throw new CopilotException('The AI service returned an empty response.', 502);
                    }

                    if ($this->groundingAdvisor->shouldForceKnowledgeSearch($history, $question, $toolsUsed)) {
                        $this->injectProactiveKnowledgeSearch(
                            $executionContext,
                            $history,
                            $question,
                            $toolsUsed,
                            $sourcesUsed,
                            $traceRecorder,
                            $conversationItems,
                        );

                        $input = $conversationItems;

                        continue;
                    }

                    $latencyMs = $this->latencyMs($startedAt);

                    if ($persistLog) {
                        $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'success', null, $traceRecorder);
                    }

                    return [
                        'answer' => $answer,
                        'tools_used' => array_values(array_unique($toolsUsed)),
                        'sources_used' => array_values(array_unique($sourcesUsed)),
                        'latency_ms' => $latencyMs,
                        'error' => null,
                        'status_code' => null,
                        'reference' => null,
                    ];
                }

                $toolOutputs = [];

                foreach ($functionCalls as $call) {
                    $toolsUsed[] = $call['name'];
                    $toolStartedAt = hrtime(true);

                    try {
                        $arguments = json_decode($call['arguments'], true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $arguments = [];
                    }

                    if (! is_array($arguments)) {
                        $arguments = [];
                    }

                    try {
                        $result = $this->toolExecutor->validateAndExecute($executionContext, $call['name'], $arguments);
                        $toolStatus = isset($result['error']) ? 'failure' : 'success';
                    } catch (AuthorizationException $exception) {
                        $result = [
                            'error' => $exception->getMessage() ?: 'Not authorized to access this resource.',
                        ];
                        $toolStatus = 'failure';
                    }

                    $toolDurationMs = (int) round((hrtime(true) - $toolStartedAt) / 1_000_000);
                    $toolMetadata = $this->toolMetadata($call['name'], $result);
                    $traceRecorder->recordToolCall($call['name'], $toolDurationMs, $toolStatus, $toolMetadata);

                    if ($call['name'] === 'search_knowledge' && isset($result['results']) && is_array($result['results'])) {
                        $traceRecorder->recordRagUsage(count($result['results']));
                    }

                    $sourcesUsed = array_merge($sourcesUsed, $this->extractSourcesFromToolResult($call['name'], $result));

                    $toolOutputs[] = [
                        'type' => 'function_call_output',
                        'call_id' => $call['call_id'],
                        'output' => json_encode($result, JSON_THROW_ON_ERROR),
                    ];
                }

                $functionCallItems = $this->openAI->extractFunctionCallItems($response);

                if ($conversationItems === null) {
                    $conversationItems = [
                        ...$this->conversationSeedItems($history, $question),
                        ...$functionCallItems,
                        ...$toolOutputs,
                    ];
                } else {
                    $conversationItems = [
                        ...$conversationItems,
                        ...$functionCallItems,
                        ...$toolOutputs,
                    ];
                }

                $input = $conversationItems;
            }

            throw new CopilotException('The copilot exceeded the maximum number of tool calls.', 502);
        } catch (CopilotException $exception) {
            $reference = null;

            if ($persistLog) {
                $trace = $this->logRequest(
                    $context,
                    $question,
                    $model,
                    $toolsUsed,
                    $startedAt,
                    'failure',
                    $exception->getMessage(),
                    $traceRecorder,
                );
                $reference = $trace->id;
                $this->incidentService->createFromAiFailure($trace, $exception->getMessage());
            }

            return [
                'answer' => null,
                'tools_used' => array_values(array_unique($toolsUsed)),
                'sources_used' => array_values(array_unique($sourcesUsed)),
                'latency_ms' => $this->latencyMs($startedAt),
                'error' => $exception->getMessage(),
                'status_code' => $exception->statusCode,
                'reference' => $reference,
            ];
        } catch (\Throwable $exception) {
            $reference = null;

            if ($persistLog) {
                $trace = $this->logRequest(
                    $context,
                    $question,
                    $model,
                    $toolsUsed,
                    $startedAt,
                    'failure',
                    'An unexpected copilot error occurred.',
                    $traceRecorder,
                );
                $reference = $trace->id;
                $this->incidentService->createFromAiFailure($trace, 'An unexpected copilot error occurred.');
            }

            return [
                'answer' => null,
                'tools_used' => array_values(array_unique($toolsUsed)),
                'sources_used' => array_values(array_unique($sourcesUsed)),
                'latency_ms' => $this->latencyMs($startedAt),
                'error' => 'An unexpected copilot error occurred.',
                'status_code' => 503,
                'reference' => $reference,
            ];
        }
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function conversationSeedItems(array $history, string $question): array
    {
        $input = $this->buildInput($history, $question);

        return is_array($input) ? $input : [$this->openAI->userMessageItem($input)];
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return string|array<int, array<string, mixed>>
     */
    private function buildInput(array $history, string $question): string|array
    {
        if ($history === []) {
            return $question;
        }

        $items = [];

        foreach ($history as $turn) {
            $items[] = $this->openAI->userMessageItem($turn['question']);
            $items[] = $this->openAI->assistantMessageItem($turn['answer']);
        }

        $items[] = $this->openAI->userMessageItem($question);

        return $items;
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return array<int, array{question: string, answer: string}>
     */
    private function normalizeHistory(array $history): array
    {
        $normalized = [];

        foreach ($history as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $question = $turn['question'] ?? null;
            $answer = $turn['answer'] ?? null;

            if (! is_string($question) || $question === '' || ! is_string($answer) || $answer === '') {
                continue;
            }

            $normalized[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        if (count($normalized) <= self::MAX_HISTORY_TURNS) {
            return $normalized;
        }

        return array_slice($normalized, -self::MAX_HISTORY_TURNS);
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     */
    private function executionContext(CopilotContext $context, string $question, array $history): CopilotContext
    {
        return new CopilotContext(
            user: $context->user,
            workspace: $context->workspace,
            customer: $context->customer,
            deployment: $context->deployment,
            currentQuestion: $question,
            userQuestionHistory: array_values(array_map(
                static fn (array $turn): string => $turn['question'],
                $history,
            )),
        );
    }

    private function instructions(CopilotContext $context, array $history, string $question): string
    {
        $instructions = sprintf(
            'You are DeployOps AI Copilot, a read-only assistant for deployment operations. '.
            'You are scoped to workspace "%s", customer "%s", and deployment "%s". '.
            'Prior assistant replies in the conversation are conversational context only and are not authoritative project knowledge. '.
            'Always use tools to fetch factual deployment, operational, and documentation data before answering. '.
            'For any project-specific factual question about uploaded documentation, policies, runbooks, or guides, call search_knowledge before answering. '.
            'Short contextual follow-ups that inherit a prior user topic still require search_knowledge; never rely on prior assistant replies as evidence. '.
            'Only cite knowledge that appears in search_knowledge results and include source filenames. '.
            'Do not ask the user for permission to search documentation. '.
            'If search_knowledge returns no relevant results, say the information is undocumented or not in the knowledge base. '.
            'For operational health, incidents, or AI reliability questions, use get_ai_health_summary, list_recent_incidents, or get_incident. '.
            'Never invent deployment, customer, integration, or documentation details. '.
            'Only reference integration IDs returned by list_deployment_integrations. '.
            'To change deployment stage, use propose_update_deployment_stage which creates a pending action for human approval. '.
            'Never claim a deployment stage was changed unless an action was proposed and approved. '.
            'Do not request or expose secrets such as API keys or webhook secrets.',
            $context->workspace->name,
            $context->customer->name,
            $context->deployment->name,
        );

        $directive = $this->groundingAdvisor->groundingDirective($history, $question);

        if ($directive !== null) {
            $instructions .= ' '.$directive;
        }

        return $instructions;
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @param  array<int, string>  $toolsUsed
     * @param  array<int, string>  $sourcesUsed
     * @param  array<int, array<string, mixed>>|null  $conversationItems
     */
    private function injectProactiveKnowledgeSearch(
        CopilotContext $executionContext,
        array $history,
        string $question,
        array &$toolsUsed,
        array &$sourcesUsed,
        AiTraceRecorder $traceRecorder,
        ?array &$conversationItems,
    ): void {
        $searchQuery = $this->groundingAdvisor->buildSearchQuery($question, $history);
        $topK = (int) config('services.knowledge.default_top_k', 5);
        $arguments = [
            'query' => $searchQuery,
            'top_k' => $topK,
        ];
        $callId = 'call_proactive_search_knowledge';
        $toolStartedAt = hrtime(true);

        try {
            $result = $this->toolExecutor->validateAndExecute($executionContext, 'search_knowledge', $arguments);
            $toolStatus = isset($result['error']) ? 'failure' : 'success';
        } catch (AuthorizationException $exception) {
            $result = [
                'error' => $exception->getMessage() ?: 'Not authorized to access this resource.',
            ];
            $toolStatus = 'failure';
        }

        $toolsUsed[] = 'search_knowledge';
        $toolDurationMs = (int) round((hrtime(true) - $toolStartedAt) / 1_000_000);
        $toolMetadata = $this->toolMetadata('search_knowledge', $result);
        $traceRecorder->recordToolCall('search_knowledge', $toolDurationMs, $toolStatus, $toolMetadata);

        if (isset($result['results']) && is_array($result['results'])) {
            $traceRecorder->recordRagUsage(count($result['results']));
        }

        $sourcesUsed = array_merge($sourcesUsed, $this->extractSourcesFromToolResult('search_knowledge', $result));

        $functionCallItem = $this->openAI->functionCallItem(
            $callId,
            'search_knowledge',
            json_encode($arguments, JSON_THROW_ON_ERROR),
        );
        $toolOutput = [
            'type' => 'function_call_output',
            'call_id' => $callId,
            'output' => json_encode($result, JSON_THROW_ON_ERROR),
        ];

        if ($conversationItems === null) {
            $conversationItems = [
                ...$this->conversationSeedItems($history, $question),
                $functionCallItem,
                $toolOutput,
            ];

            return;
        }

        $conversationItems = [
            ...$conversationItems,
            $functionCallItem,
            $toolOutput,
        ];
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function toolMetadata(string $toolName, array $result): array
    {
        if (isset($result['error'])) {
            return [
                'tool' => $toolName,
                'error' => is_string($result['error']) ? $result['error'] : 'Tool failed.',
            ];
        }

        return match ($toolName) {
            'search_knowledge' => [
                'result_count' => is_array($result['results'] ?? null) ? count($result['results']) : 0,
            ],
            'list_recent_incidents' => [
                'incident_count' => is_array($result['incidents'] ?? null) ? count($result['incidents']) : 0,
            ],
            'get_incident' => [
                'incident_id' => $result['id'] ?? null,
            ],
            default => ['tool' => $toolName],
        };
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
        AiTraceRecorder $traceRecorder,
    ): CopilotRequestLog {
        return $traceRecorder->persist(
            $context,
            $question,
            $model,
            $toolsUsed,
            $startedAt,
            $status,
            $errorMessage,
            $this->questionRedactor,
            $this->costEstimator,
        );
    }
}
