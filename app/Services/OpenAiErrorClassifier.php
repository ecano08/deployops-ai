<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class OpenAiErrorClassifier
{
    /**
     * @return array{
     *   message: string,
     *   status_code: int,
     *   category: string,
     *   diagnostics: array{
     *     http_status: int,
     *     provider_error_type: string|null,
     *     provider_error_code: string|null,
     *     provider_message: string|null,
     *     model: string
     *   }
     * }
     */
    public function classify(Response $response): array
    {
        $httpStatus = $response->status();
        /** @var array<string, mixed>|null $error */
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $providerType = $this->stringValue($error['type'] ?? null);
        $providerCode = $this->stringValue($error['code'] ?? null);
        $providerMessage = $this->sanitizeProviderMessage($this->stringValue($error['message'] ?? null));
        $model = (string) config('services.openai.model');

        $category = $this->resolveCategory($httpStatus, $providerType, $providerCode, $providerMessage);

        $diagnostics = [
            'http_status' => $httpStatus,
            'provider_error_type' => $providerType,
            'provider_error_code' => $providerCode,
            'provider_message' => $providerMessage,
            'model' => $model,
        ];

        Log::warning('OpenAI responses API error', [
            'category' => $category,
            ...$diagnostics,
        ]);

        return [
            'message' => $this->userMessage($category),
            'status_code' => $this->statusCode($category, $httpStatus),
            'category' => $category,
            'diagnostics' => $diagnostics,
        ];
    }

    private function resolveCategory(
        int $httpStatus,
        ?string $providerType,
        ?string $providerCode,
        ?string $providerMessage,
    ): string {
        $providerCode = $providerCode !== null ? strtolower($providerCode) : null;
        $providerType = $providerType !== null ? strtolower($providerType) : null;
        $providerMessage = $providerMessage !== null ? strtolower($providerMessage) : null;

        if (in_array($providerCode, ['invalid_api_key', 'incorrect_api_key'], true)) {
            return 'authentication';
        }

        if ($providerType === 'authentication_error' || in_array($httpStatus, [401, 403], true)) {
            return 'authentication';
        }

        if ($providerCode === 'rate_limit_exceeded' || $httpStatus === 429) {
            return 'rate_limit';
        }

        if (
            in_array($providerCode, ['model_not_found', 'model_not_available'], true)
            || ($httpStatus === 404 && str_contains((string) $providerMessage, 'model'))
        ) {
            return 'model_unavailable';
        }

        if (
            in_array($providerType, ['invalid_request_error', 'invalid_tool_definition'], true)
            || in_array($httpStatus, [400, 422], true)
        ) {
            return 'invalid_request';
        }

        if ($httpStatus >= 500) {
            return 'provider_unavailable';
        }

        return 'unknown';
    }

    private function userMessage(string $category): string
    {
        return match ($category) {
            'authentication' => 'OpenAI authentication failed. Check the configured API key.',
            'rate_limit' => 'The AI provider rate limit was reached. Try again shortly.',
            'invalid_request' => 'The AI request was rejected because of an invalid request or tool definition.',
            'model_unavailable' => 'The configured AI model is unavailable.',
            'provider_unavailable' => 'The AI provider is temporarily unavailable.',
            default => 'The AI service returned an unexpected error.',
        };
    }

    private function statusCode(string $category, int $httpStatus): int
    {
        return match ($category) {
            'authentication' => 503,
            'rate_limit' => 429,
            'invalid_request' => 502,
            'model_unavailable' => 503,
            'provider_unavailable' => 503,
            default => $httpStatus >= 500 ? 503 : 502,
        };
    }

    private function sanitizeProviderMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $redacted = preg_replace('/\bsk-[a-zA-Z0-9]{8,}/', '[REDACTED]', $message) ?? $message;
        $redacted = preg_replace('/\bwhsec_[a-zA-Z0-9]+/', '[REDACTED]', $redacted) ?? $redacted;
        $redacted = preg_replace(
            '/(?i)(api[_-]?key|token|password|secret|authorization)\s*[:=]\s*\S+/',
            '[REDACTED]',
            $redacted,
        ) ?? $redacted;

        return mb_substr(trim($redacted), 0, 500);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
