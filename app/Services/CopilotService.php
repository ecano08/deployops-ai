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
        $startedAt = hrtime(true);
        $toolsUsed = [];
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

                    $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'success', null);

                    return [
                        'answer' => $answer,
                        'tools_used' => array_values(array_unique($toolsUsed)),
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
            $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'failure', $exception->getMessage());

            throw $exception;
        } catch (\Throwable $exception) {
            $this->logRequest($context, $question, $model, $toolsUsed, $startedAt, 'failure', 'An unexpected copilot error occurred.');

            throw new CopilotException('An unexpected copilot error occurred.', 503);
        }
    }

    private function instructions(CopilotContext $context): string
    {
        return sprintf(
            'You are DeployOps AI Copilot, a read-only assistant for deployment operations. '.
            'You are scoped to workspace "%s", customer "%s", and deployment "%s". '.
            'Use the provided tools to fetch factual data before answering. '.
            'Never invent deployment, customer, or integration details. '.
            'Only reference integration IDs returned by list_deployment_integrations. '.
            'Do not request or expose secrets such as API keys or webhook secrets.',
            $context->workspace->name,
            $context->customer->name,
            $context->deployment->name,
        );
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
