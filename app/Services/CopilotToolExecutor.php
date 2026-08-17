<?php

namespace App\Services;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\DeploymentIntegrationResource;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\IntegrationActivityResource;
use App\Models\DeploymentIntegration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CopilotToolExecutor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->noArgumentTool(
                'get_customer',
                'Get the current customer for this deployment context.',
            ),
            $this->noArgumentTool(
                'get_deployment',
                'Get the current deployment for this copilot session.',
            ),
            $this->noArgumentTool(
                'list_deployment_integrations',
                'List integrations configured for the current deployment.',
            ),
            $this->integrationIdTool(
                'get_integration_status',
                'Get connection status for an integration belonging to the current deployment.',
            ),
            $this->integrationIdTool(
                'list_integration_activities',
                'List recent activity log entries for an integration in the current deployment.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateAndExecute(CopilotContext $context, string $name, array $arguments): array
    {
        $validation = $this->validateArguments($name, $arguments);

        if ($validation !== null) {
            return $validation;
        }

        return $this->execute($context, $name, $arguments);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateArguments(string $name, array $arguments): ?array
    {
        $allowedKeys = match ($name) {
            'get_customer', 'get_deployment', 'list_deployment_integrations' => [],
            'get_integration_status', 'list_integration_activities' => ['integration_id'],
            default => null,
        };

        if ($allowedKeys === null) {
            return ['error' => 'Unknown tool.'];
        }

        $unexpectedKeys = array_diff(array_keys($arguments), $allowedKeys);

        if ($unexpectedKeys !== []) {
            return ['error' => 'Unexpected tool arguments.'];
        }

        if ($allowedKeys === []) {
            if ($arguments !== []) {
                return ['error' => 'Unexpected tool arguments.'];
            }

            return null;
        }

        if (! array_key_exists('integration_id', $arguments)) {
            return ['error' => 'Missing required tool argument.'];
        }

        $integrationId = $arguments['integration_id'];

        if (! is_int($integrationId)) {
            if (is_string($integrationId) && ctype_digit($integrationId)) {
                $arguments['integration_id'] = (int) $integrationId;
            } else {
                return ['error' => 'Invalid integration identifier.'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function noArgumentTool(string $name, string $description): array
    {
        return [
            'type' => 'function',
            'name' => $name,
            'description' => $description,
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationIdTool(string $name, string $description): array
    {
        return [
            'type' => 'function',
            'name' => $name,
            'description' => $description,
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'integration_id' => [
                        'type' => 'integer',
                        'description' => 'Integration ID from list_deployment_integrations.',
                    ],
                ],
                'required' => ['integration_id'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(CopilotContext $context, string $name, array $arguments): array
    {
        return match ($name) {
            'get_customer' => $this->getCustomer($context),
            'get_deployment' => $this->getDeployment($context),
            'list_deployment_integrations' => $this->listDeploymentIntegrations($context),
            'get_integration_status' => $this->getIntegrationStatus($context, $arguments),
            'list_integration_activities' => $this->listIntegrationActivities($context, $arguments),
            default => [
                'error' => 'Unknown tool.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getCustomer(CopilotContext $context): array
    {
        Gate::forUser($context->user)->authorize('view', $context->customer);

        return CustomerResource::make($context->customer)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function getDeployment(CopilotContext $context): array
    {
        Gate::forUser($context->user)->authorize('view', $context->deployment);

        return DeploymentResource::make($context->deployment)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function listDeploymentIntegrations(CopilotContext $context): array
    {
        Gate::forUser($context->user)->authorize('view', $context->deployment);

        $integrations = $context->deployment->integrations()
            ->orderBy('name')
            ->get();

        return [
            'integrations' => DeploymentIntegrationResource::collection($integrations)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getIntegrationStatus(CopilotContext $context, array $arguments): array
    {
        $integration = $this->resolveIntegration($context, $arguments);

        Gate::forUser($context->user)->authorize('view', $integration);

        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'type' => $integration->type->value,
            'status' => $integration->status->value,
            'has_api_key' => $integration->apiKey() !== null,
            'has_webhook_secret' => $integration->webhookSecret() !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listIntegrationActivities(CopilotContext $context, array $arguments): array
    {
        $integration = $this->resolveIntegration($context, $arguments);

        Gate::forUser($context->user)->authorize('viewActivities', $integration);

        $activities = $integration->activities()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return [
            'activities' => IntegrationActivityResource::collection($activities)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveIntegration(CopilotContext $context, array $arguments): DeploymentIntegration
    {
        $integrationId = $arguments['integration_id'];

        if (! is_int($integrationId)) {
            throw new AuthorizationException('Invalid integration identifier.');
        }

        $integration = $context->deployment->integrations()
            ->whereKey($integrationId)
            ->first();

        if ($integration === null) {
            throw new AuthorizationException('Integration not found in this deployment.');
        }

        if ($integration->workspace_id !== $context->workspace->id) {
            throw new AuthorizationException('Integration not found in this workspace.');
        }

        return $integration;
    }
}
