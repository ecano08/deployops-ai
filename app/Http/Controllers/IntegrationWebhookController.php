<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationActivityType;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\DeploymentIntegration;
use App\Services\IntegrationConnectionTester;
use App\Services\WebhookReplayGuard;
use App\Services\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationWebhookController extends Controller
{
    public function store(
        Request $request,
        DeploymentIntegration $deploymentIntegration,
        WebhookSignatureVerifier $verifier,
        WebhookReplayGuard $replayGuard,
        IntegrationConnectionTester $tester,
    ): JsonResponse {
        if ($deploymentIntegration->type !== IntegrationType::Webhook) {
            return response()->json([
                'message' => 'Integration does not accept webhook events.',
            ], 422);
        }

        $secret = $deploymentIntegration->webhookSecret();

        if ($secret === null) {
            return response()->json([
                'message' => 'Webhook secret is not configured.',
            ], 422);
        }

        $payload = $request->getContent();
        $signature = $request->header('X-Integration-Signature');
        $timestamp = $request->header('X-Integration-Timestamp');

        $verification = $verifier->verify($payload, $secret, $signature, $timestamp);

        if (! $verification['valid']) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        if ($replayGuard->isReplay($deploymentIntegration, (string) $signature)) {
            return response()->json([
                'message' => 'Webhook replay detected.',
            ], 409);
        }

        $decoded = json_decode($payload, true);
        $eventType = is_array($decoded) ? ($decoded['event'] ?? $decoded['type'] ?? null) : null;

        $tester->logActivity(
            $deploymentIntegration,
            IntegrationActivityType::WebhookReceived,
            'success',
            [
                'event_type' => is_string($eventType) ? $eventType : null,
                'payload_size' => strlen($payload),
            ],
            'Webhook event received.',
        );

        $deploymentIntegration->update(['status' => IntegrationStatus::Connected]);

        $replayGuard->remember(
            $deploymentIntegration,
            (string) $signature,
            WebhookSignatureVerifier::MAX_AGE_SECONDS,
        );

        return response()->json([
            'data' => [
                'received' => true,
            ],
        ]);
    }
}
