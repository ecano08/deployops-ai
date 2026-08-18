<?php

namespace App\Services;

use App\Exceptions\CopilotException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAIResponsesClient
{
    private const string API_URL = 'https://api.openai.com/v1/responses';

    public function __construct(
        private OpenAiErrorClassifier $errorClassifier,
    ) {}

    /**
     * @param  string|array<int, mixed>  $input
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array{max_output_tokens?: int, timeout?: int, tool_choice?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    public function create(
        string $instructions,
        string|array $input,
        array $tools,
        array $options = [],
    ): array {
        $apiKey = config('services.openai.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new CopilotException('OpenAI API key is not configured.', 503);
        }

        $payload = [
            'model' => config('services.openai.model'),
            'instructions' => $instructions,
            'input' => $input,
            'tools' => $tools,
            'max_output_tokens' => (int) ($options['max_output_tokens'] ?? config('services.openai.max_output_tokens')),
            'store' => false,
        ];

        if (isset($options['tool_choice']) && is_array($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        try {
            $response = Http::timeout((int) ($options['timeout'] ?? config('services.openai.timeout')))
                ->connectTimeout((int) config('services.openai.connect_timeout'))
                ->withToken($apiKey)
                ->acceptJson()
                ->post(self::API_URL, $payload);
        } catch (ConnectionException) {
            throw new CopilotException('The AI service timed out. Please try again.', 503);
        }

        if ($response->failed()) {
            $classified = $this->errorClassifier->classify($response);

            throw new CopilotException(
                $classified['message'],
                $classified['status_code'],
            );
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractOutputText(array $response): ?string
    {
        $outputText = $response['output_text'] ?? null;

        if (is_string($outputText) && $outputText !== '') {
            return $outputText;
        }

        $output = $response['output'] ?? null;

        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    return $part['text'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array{call_id: string, name: string, arguments: string}>
     */
    public function extractFunctionCalls(array $response): array
    {
        $output = $response['output'] ?? null;

        if (! is_array($output)) {
            return [];
        }

        $calls = [];

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                continue;
            }

            $callId = $item['call_id'] ?? null;
            $name = $item['name'] ?? null;
            $arguments = $item['arguments'] ?? '{}';

            if (! is_string($callId) || ! is_string($name)) {
                continue;
            }

            if (is_array($arguments)) {
                $encoded = json_encode($arguments);
                $arguments = is_string($encoded) ? $encoded : '{}';
            } elseif (! is_string($arguments) || $arguments === '') {
                $arguments = '{}';
            }

            $calls[] = [
                'call_id' => $callId,
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $calls;
    }

    /**
     * @return array{type: string, role: string, content: array<int, array{type: string, text: string}>}
     */
    public function userMessageItem(string $text): array
    {
        return [
            'type' => 'message',
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, role: string, content: array<int, array{type: string, text: string}>}
     */
    public function assistantMessageItem(string $text): array
    {
        return [
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'output_text',
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, call_id: string, name: string, arguments: string}
     */
    public function functionCallItem(string $callId, string $name, string $arguments): array
    {
        return [
            'type' => 'function_call',
            'call_id' => $callId,
            'name' => $name,
            'arguments' => $arguments,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    public function extractFunctionCallItems(array $response): array
    {
        $output = $response['output'] ?? null;

        if (! is_array($output)) {
            return [];
        }

        $items = [];

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }
}
