<?php

use Illuminate\Support\Facades\Http;

it('returns the api health status', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'api',
        ]);
});

it('can reach the ai service', function () {
    Http::fake([
        config('services.ai_service.url').'/health' => Http::response([
            'status' => 'ok',
            'service' => 'ai-service',
        ]),
    ]);

    $this->getJson('/api/health/ai')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'ai_service' => 'connected',
            'details' => [
                'status' => 'ok',
                'service' => 'ai-service',
            ],
        ]);
});

it('returns unavailable when the ai service connection fails', function () {
    Http::fake([
        config('services.ai_service.url').'/health' => Http::failedConnection(),
    ]);

    $this->getJson('/api/health/ai')
        ->assertServiceUnavailable()
        ->assertJson([
            'status' => 'error',
            'ai_service' => 'unavailable',
            'message' => 'AI service is unreachable.',
        ]);
});

it('returns unavailable when the ai service returns an upstream error', function () {
    Http::fake([
        config('services.ai_service.url').'/health' => Http::response('Internal Server Error', 500),
    ]);

    $this->getJson('/api/health/ai')
        ->assertServiceUnavailable()
        ->assertJson([
            'status' => 'error',
            'ai_service' => 'unavailable',
            'message' => 'AI service returned an error.',
        ]);
});
