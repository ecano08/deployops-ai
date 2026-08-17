<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'api',
    ]);
});

Route::get('/health/ai', function () {
    try {
        $response = Http::timeout(3)
            ->connectTimeout(2)
            ->get(config('services.ai_service.url').'/health');

        if ($response->failed()) {
            return response()->json([
                'status' => 'error',
                'ai_service' => 'unavailable',
                'message' => 'AI service returned an error.',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'ai_service' => 'connected',
            'details' => $response->json(),
        ]);
    } catch (ConnectionException) {
        return response()->json([
            'status' => 'error',
            'ai_service' => 'unavailable',
            'message' => 'AI service is unreachable.',
        ], 503);
    }
});
