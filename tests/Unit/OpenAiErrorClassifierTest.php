<?php

use App\Services\OpenAiErrorClassifier;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['services.openai.model' => 'gpt-4.1-mini']);
    Log::spy();
});

it('classifies authentication failures without leaking provider secrets', function () {
    $response = new Response(new GuzzleHttp\Psr7\Response(
        401,
        ['Content-Type' => 'application/json'],
        json_encode([
            'error' => [
                'message' => 'Incorrect API key provided: sk-secret1234567890',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], JSON_THROW_ON_ERROR),
    ));

    $classified = app(OpenAiErrorClassifier::class)->classify($response);

    expect($classified['message'])->toBe('OpenAI authentication failed. Check the configured API key.')
        ->and($classified['status_code'])->toBe(503)
        ->and($classified['category'])->toBe('authentication')
        ->and($classified['diagnostics']['provider_message'])->not->toContain('sk-secret1234567890');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('OpenAI responses API error', Mockery::on(function (array $context): bool {
            return ($context['category'] ?? null) === 'authentication'
                && ($context['http_status'] ?? null) === 401
                && ($context['provider_error_code'] ?? null) === 'invalid_api_key'
                && ($context['model'] ?? null) === 'gpt-4.1-mini';
        }));
});

it('classifies rate limits and invalid requests', function () {
    $rateLimitResponse = new Response(new GuzzleHttp\Psr7\Response(
        429,
        ['Content-Type' => 'application/json'],
        json_encode([
            'error' => [
                'message' => 'Rate limit reached for requests',
                'type' => 'rate_limit_error',
                'code' => 'rate_limit_exceeded',
            ],
        ], JSON_THROW_ON_ERROR),
    ));

    $invalidRequestResponse = new Response(new GuzzleHttp\Psr7\Response(
        400,
        ['Content-Type' => 'application/json'],
        json_encode([
            'error' => [
                'message' => 'Invalid schema for function tools[0].',
                'type' => 'invalid_request_error',
            ],
        ], JSON_THROW_ON_ERROR),
    ));

    $classifier = app(OpenAiErrorClassifier::class);

    $rateLimit = $classifier->classify($rateLimitResponse);
    expect($rateLimit['message'])->toBe('The AI provider rate limit was reached. Try again shortly.')
        ->and($rateLimit['status_code'])->toBe(429)
        ->and($rateLimit['category'])->toBe('rate_limit');

    $invalidRequest = $classifier->classify($invalidRequestResponse);
    expect($invalidRequest['message'])->toBe('The AI request was rejected because of an invalid request or tool definition.')
        ->and($invalidRequest['status_code'])->toBe(502)
        ->and($invalidRequest['category'])->toBe('invalid_request');
});
