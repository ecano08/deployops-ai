<?php

namespace App\Services;

use App\Enums\AiActionType;
use App\Enums\DeploymentStage;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\DeploymentIntegrationResource;
use App\Http\Resources\DeploymentResource;
use App\Http\Resources\IntegrationActivityResource;
use App\Models\AiProposedAction;
use App\Models\CopilotRequestLog;
use App\Models\DeploymentIntegration;
use App\Models\Incident;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CopilotToolExecutor
{
    public function __construct(
        private KnowledgeSearchService $knowledgeSearch,
        private AiActionService $aiActionService,
        private AiHealthMetricsService $aiHealthMetrics,
    ) {}

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
            $this->searchKnowledgeTool(),
            $this->proposeUpdateDeploymentStageTool(),
            $this->noArgumentTool(
                'list_recent_incidents',
                'List recent incidents for the current deployment.',
            ),
            $this->incidentIdTool(),
            $this->noArgumentTool(
                'get_ai_health_summary',
                'Get AI health metrics for the current deployment including request count, failure rate, latency, token usage, and estimated cost.',
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
            'get_customer', 'get_deployment', 'list_deployment_integrations', 'list_recent_incidents', 'get_ai_health_summary' => [],
            'get_integration_status', 'list_integration_activities' => ['integration_id'],
            'search_knowledge' => ['query', 'top_k'],
            'propose_update_deployment_stage' => ['stage'],
            'get_incident' => ['incident_id'],
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

        if ($name === 'search_knowledge') {
            return $this->validateSearchKnowledgeArguments($arguments);
        }

        if ($name === 'propose_update_deployment_stage') {
            return $this->validateProposeUpdateDeploymentStageArguments($arguments);
        }

        if ($name === 'get_incident') {
            return $this->validateIncidentIdArguments($arguments);
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
    private function searchKnowledgeTool(): array
    {
        return [
            'type' => 'function',
            'name' => 'search_knowledge',
            'description' => 'Search deployment-scoped knowledge documents for relevant passages. Use before answering questions about uploaded runbooks, guides, or documentation.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Natural language search query.',
                    ],
                    'top_k' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of chunks to return.',
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function validateSearchKnowledgeArguments(array $arguments): ?array
    {
        if (! array_key_exists('query', $arguments)) {
            return ['error' => 'Missing required tool argument.'];
        }

        if (! is_string($arguments['query']) || trim($arguments['query']) === '') {
            return ['error' => 'Invalid search query.'];
        }

        if (array_key_exists('top_k', $arguments) && ! is_int($arguments['top_k'])) {
            if (is_string($arguments['top_k']) && ctype_digit($arguments['top_k'])) {
                return null;
            }

            return ['error' => 'Invalid top_k value.'];
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
            'search_knowledge' => $this->searchKnowledge($context, $arguments),
            'propose_update_deployment_stage' => $this->proposeUpdateDeploymentStage($context, $arguments),
            'list_recent_incidents' => $this->listRecentIncidents($context),
            'get_incident' => $this->getIncident($context, $arguments),
            'get_ai_health_summary' => $this->getAiHealthSummary($context),
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
     * @return array<string, mixed>
     */
    private function searchKnowledge(CopilotContext $context, array $arguments): array
    {
        Gate::forUser($context->user)->authorize('view', $context->deployment);

        $query = trim((string) $arguments['query']);
        $topK = array_key_exists('top_k', $arguments) ? (int) $arguments['top_k'] : null;

        try {
            $results = $this->knowledgeSearch->search($context, $query, $topK);
        } catch (\Throwable) {
            return [
                'error' => 'Knowledge search is temporarily unavailable.',
            ];
        }

        return [
            'query' => $query,
            'results' => array_map(
                static fn (array $result): array => [
                    'document_id' => $result['document_id'],
                    'source_filename' => $result['source_filename'],
                    'chunk_index' => $result['chunk_index'],
                    'content' => $result['content'],
                    'score' => $result['score'],
                ],
                $results,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposeUpdateDeploymentStageTool(): array
    {
        return [
            'type' => 'function',
            'name' => 'propose_update_deployment_stage',
            'description' => 'Propose changing the deployment stage. Creates a pending action that requires owner or admin approval before execution.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'stage' => [
                        'type' => 'string',
                        'enum' => DeploymentStage::values(),
                        'description' => 'Target deployment stage.',
                    ],
                ],
                'required' => ['stage'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function validateProposeUpdateDeploymentStageArguments(array $arguments): ?array
    {
        if (! array_key_exists('stage', $arguments) || ! is_string($arguments['stage'])) {
            return ['error' => 'Missing required tool argument.'];
        }

        if (DeploymentStage::tryFrom($arguments['stage']) === null) {
            return ['error' => 'Invalid deployment stage.'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeUpdateDeploymentStage(CopilotContext $context, array $arguments): array
    {
        Gate::forUser($context->user)->authorize('propose', [AiProposedAction::class, $context->deployment]);

        $action = $this->aiActionService->propose(
            requester: $context->user,
            deployment: $context->deployment,
            actionType: AiActionType::UpdateDeploymentStage,
            payload: ['stage' => $arguments['stage']],
        );

        return [
            'action_id' => $action->id,
            'status' => $action->status->value,
            'message' => 'Deployment stage change proposed and pending approval.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentIdTool(): array
    {
        return [
            'type' => 'function',
            'name' => 'get_incident',
            'description' => 'Get details for a specific incident in the current deployment.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'incident_id' => [
                        'type' => 'integer',
                        'description' => 'Incident ID from list_recent_incidents.',
                    ],
                ],
                'required' => ['incident_id'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function validateIncidentIdArguments(array $arguments): ?array
    {
        if (! array_key_exists('incident_id', $arguments)) {
            return ['error' => 'Missing required tool argument.'];
        }

        $incidentId = $arguments['incident_id'];

        if (! is_int($incidentId)) {
            if (is_string($incidentId) && ctype_digit($incidentId)) {
                return null;
            }

            return ['error' => 'Invalid incident identifier.'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function listRecentIncidents(CopilotContext $context): array
    {
        Gate::forUser($context->user)->authorize('viewAny', [Incident::class, $context->deployment]);

        $incidents = $context->deployment->incidents()
            ->where('workspace_id', $context->workspace->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'incidents' => $incidents->map(static fn (Incident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'severity' => $incident->severity->value,
                'status' => $incident->status->value,
                'source' => $incident->source->value,
                'created_at' => $incident->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getIncident(CopilotContext $context, array $arguments): array
    {
        $incident = $this->resolveIncident($context, $arguments);

        Gate::forUser($context->user)->authorize('view', $incident);

        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'description' => $incident->description,
            'severity' => $incident->severity->value,
            'status' => $incident->status->value,
            'source' => $incident->source->value,
            'root_cause' => $incident->root_cause,
            'resolution' => $incident->resolution,
            'created_at' => $incident->created_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAiHealthSummary(CopilotContext $context): array
    {
        Gate::forUser($context->user)->authorize('viewAny', [CopilotRequestLog::class, $context->deployment]);

        return $this->aiHealthMetrics->summarize($context->deployment);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveIncident(CopilotContext $context, array $arguments): Incident
    {
        $incidentId = $arguments['incident_id'];

        if (! is_int($incidentId)) {
            if (is_string($incidentId) && ctype_digit($incidentId)) {
                $incidentId = (int) $incidentId;
            } else {
                throw new AuthorizationException('Invalid incident identifier.');
            }
        }

        $incident = $context->deployment->incidents()
            ->whereKey($incidentId)
            ->where('workspace_id', $context->workspace->id)
            ->first();

        if ($incident === null) {
            throw new AuthorizationException('Incident not found in this deployment.');
        }

        return $incident;
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
