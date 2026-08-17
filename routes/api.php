<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
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

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
    Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
    Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store']);
    Route::patch('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])->scopeBindings();
    Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])->scopeBindings();
});
