<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeploymentIntegrationController;
use App\Http\Controllers\IntegrationActivityController;
use App\Http\Controllers\IntegrationWebhookController;
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

Route::post('/webhooks/integrations/{deployment_integration}', [IntegrationWebhookController::class, 'store'])
    ->middleware('throttle:integration-webhooks');

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
    Route::get('/workspaces/{workspace}/customers', [CustomerController::class, 'index']);
    Route::post('/workspaces/{workspace}/customers', [CustomerController::class, 'store']);
    Route::get('/workspaces/{workspace}/customers/{customer}', [CustomerController::class, 'show'])->scopeBindings();
    Route::patch('/workspaces/{workspace}/customers/{customer}', [CustomerController::class, 'update'])->scopeBindings();
    Route::delete('/workspaces/{workspace}/customers/{customer}', [CustomerController::class, 'destroy'])->scopeBindings();
    Route::get('/workspaces/{workspace}/customers/{customer}/deployments', [DeploymentController::class, 'index'])->scopeBindings();
    Route::post('/workspaces/{workspace}/customers/{customer}/deployments', [DeploymentController::class, 'store'])->scopeBindings();
    Route::get('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}', [DeploymentController::class, 'show'])->scopeBindings();
    Route::patch('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}', [DeploymentController::class, 'update'])->scopeBindings();
    Route::delete('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}', [DeploymentController::class, 'destroy'])->scopeBindings();
    Route::patch('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/stage', [DeploymentController::class, 'updateStage'])->scopeBindings();
    Route::get('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations', [DeploymentIntegrationController::class, 'index'])->scopeBindings();
    Route::post('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations', [DeploymentIntegrationController::class, 'store'])->scopeBindings();
    Route::get('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations/{deployment_integration}', [DeploymentIntegrationController::class, 'show'])->scopeBindings();
    Route::patch('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations/{deployment_integration}', [DeploymentIntegrationController::class, 'update'])->scopeBindings();
    Route::delete('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations/{deployment_integration}', [DeploymentIntegrationController::class, 'destroy'])->scopeBindings();
    Route::post('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations/{deployment_integration}/test', [DeploymentIntegrationController::class, 'test'])->scopeBindings();
    Route::get('/workspaces/{workspace}/customers/{customer}/deployments/{deployment}/integrations/{deployment_integration}/activities', [IntegrationActivityController::class, 'index'])->scopeBindings();
});
