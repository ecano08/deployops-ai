<?php

namespace App\Services;

use App\Enums\IntegrationActivityType;
use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\DeploymentIntegration;
use App\Models\IntegrationActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class IntegrationConnectionTester
{
    private const int TIMEOUT_SECONDS = 5;

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(private IntegrationOutboundUrlValidator $urlValidator) {}

    /**
     * @return array{success: bool, status: IntegrationStatus, metadata: array<string, mixed>, message: ?string}
     */
    public function test(DeploymentIntegration $integration): array
    {
        if ($integration->type === IntegrationType::Webhook) {
            return $this->testWebhook($integration);
        }

        return $this->testRestApi($integration);
    }

    /**
     * @return array{success: bool, status: IntegrationStatus, metadata: array<string, mixed>, message: ?string}
     */
    private function testWebhook(DeploymentIntegration $integration): array
    {
        $secret = $integration->webhookSecret();

        if ($secret === null) {
            return $this->recordFailure(
                $integration,
                IntegrationActivityType::TestConnection,
                ['verified' => false],
                'Webhook secret is not configured.',
            );
        }

        return $this->recordSuccess(
            $integration,
            IntegrationActivityType::TestConnection,
            ['verified' => true, 'webhook_ready' => true],
            'Webhook secret is configured.',
        );
    }

    /**
     * @return array{success: bool, status: IntegrationStatus, metadata: array<string, mixed>, message: ?string}
     */
    private function testRestApi(DeploymentIntegration $integration): array
    {
        $baseUrl = (string) $integration->base_url;

        if ($baseUrl === '') {
            return $this->recordFailure(
                $integration,
                IntegrationActivityType::TestConnection,
                ['http_status' => null],
                'Base URL is required for REST API integrations.',
            );
        }

        $url = $this->urlValidator->buildSafeUrl($baseUrl, $integration->endpoint);

        if ($url === null) {
            return $this->recordFailure(
                $integration,
                IntegrationActivityType::TestConnection,
                [
                    'http_status' => null,
                    'result' => 'blocked_url',
                ],
                'The integration URL is not allowed.',
            );
        }

        try {
            $request = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->acceptJson()
                ->withoutRedirecting();

            $apiKey = $integration->apiKey();

            if ($apiKey !== null) {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get($url);
            $httpStatus = $response->status();

            if ($response->successful()) {
                return $this->recordSuccess(
                    $integration,
                    IntegrationActivityType::TestConnection,
                    [
                        'http_status' => $httpStatus,
                        'url' => $url,
                        'result' => 'reachable',
                    ],
                    'Connection successful.',
                );
            }

            return $this->recordFailure(
                $integration,
                IntegrationActivityType::TestConnection,
                [
                    'http_status' => $httpStatus,
                    'url' => $url,
                    'result' => 'http_error',
                ],
                'Remote endpoint returned an error response.',
            );
        } catch (ConnectionException) {
            return $this->recordFailure(
                $integration,
                IntegrationActivityType::TestConnection,
                [
                    'http_status' => null,
                    'url' => $url,
                    'result' => 'connection_failed',
                ],
                'Could not reach the remote endpoint.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{success: bool, status: IntegrationStatus, metadata: array<string, mixed>, message: ?string}
     */
    private function recordSuccess(
        DeploymentIntegration $integration,
        IntegrationActivityType $type,
        array $metadata,
        ?string $message,
    ): array {
        $integration->update(['status' => IntegrationStatus::Connected]);

        $this->logActivity($integration, $type, 'success', $metadata, $message);

        return [
            'success' => true,
            'status' => IntegrationStatus::Connected,
            'metadata' => $metadata,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{success: bool, status: IntegrationStatus, metadata: array<string, mixed>, message: ?string}
     */
    private function recordFailure(
        DeploymentIntegration $integration,
        IntegrationActivityType $type,
        array $metadata,
        ?string $message,
    ): array {
        $integration->update(['status' => IntegrationStatus::Error]);

        $this->logActivity($integration, $type, 'failure', $metadata, $message);

        return [
            'success' => false,
            'status' => IntegrationStatus::Error,
            'metadata' => $metadata,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function logActivity(
        DeploymentIntegration $integration,
        IntegrationActivityType $type,
        string $status,
        array $metadata = [],
        ?string $message = null,
    ): IntegrationActivity {
        return $integration->activities()->create([
            'type' => $type,
            'status' => $status,
            'metadata' => $metadata,
            'message' => $message,
        ]);
    }
}
