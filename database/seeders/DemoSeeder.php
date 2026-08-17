<?php

namespace Database\Seeders;

use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Enums\DeploymentStage;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IntegrationStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiProposedAction;
use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\EvaluationCase;
use App\Models\EvaluationDataset;
use App\Models\Incident;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DemoSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /**
     * Seed portfolio-ready demo data for local presentations.
     */
    public function run(): void
    {
        if (! DemoSeeding::isEnabled()) {
            return;
        }

        $owner = User::query()->updateOrCreate(
            ['email' => 'demo@deployops.ai'],
            [
                'name' => 'Alex Rivera',
                'password' => Hash::make('password'),
            ],
        );

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@deployops.ai'],
            [
                'name' => 'Jordan Chen',
                'password' => Hash::make('password'),
            ],
        );

        $engineer = User::query()->updateOrCreate(
            ['email' => 'engineer@deployops.ai'],
            [
                'name' => 'Sam Patel',
                'password' => Hash::make('password'),
            ],
        );

        $viewer = User::query()->updateOrCreate(
            ['email' => 'viewer@deployops.ai'],
            [
                'name' => 'Taylor Brooks',
                'password' => Hash::make('password'),
            ],
        );

        $workspace = Workspace::query()->updateOrCreate(
            ['slug' => 'acme-forward-deployed'],
            [
                'name' => 'Acme Forward Deployed',
                'owner_id' => $owner->id,
            ],
        );

        $workspace->members()->sync([
            $owner->id => ['role' => WorkspaceRole::Owner->value],
            $admin->id => ['role' => WorkspaceRole::Admin->value],
            $engineer->id => ['role' => WorkspaceRole::Engineer->value],
            $viewer->id => ['role' => WorkspaceRole::Viewer->value],
        ]);

        $globex = Customer::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'slug' => 'globex-corp'],
            [
                'name' => 'Globex Corp',
                'description' => 'Enterprise customer — AI copilot rollout in progress.',
            ],
        );

        $initech = Customer::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'slug' => 'initech'],
            [
                'name' => 'Initech',
                'description' => 'Mid-market customer — integration validation phase.',
            ],
        );

        $globexProduction = Deployment::query()->updateOrCreate(
            ['customer_id' => $globex->id, 'name' => 'Production Copilot'],
            [
                'workspace_id' => $workspace->id,
                'description' => 'Live AI copilot with RAG over customer runbooks.',
                'stage' => DeploymentStage::Deployed,
            ],
        );

        $globexStaging = Deployment::query()->updateOrCreate(
            ['customer_id' => $globex->id, 'name' => 'Staging Integration'],
            [
                'workspace_id' => $workspace->id,
                'description' => 'Pre-production environment for integration testing.',
                'stage' => DeploymentStage::Validation,
            ],
        );

        $initechDiscovery = Deployment::query()->updateOrCreate(
            ['customer_id' => $initech->id, 'name' => 'Discovery Pilot'],
            [
                'workspace_id' => $workspace->id,
                'description' => 'Early-stage discovery deployment.',
                'stage' => DeploymentStage::Discovery,
            ],
        );

        $this->seedIntegrations($globexProduction);
        $this->seedIntegrations($globexStaging);
        $this->seedIncidents($globexProduction, $owner);
        $this->seedPendingAction($globexProduction, $engineer);
        $this->seedEvaluationDataset($globexProduction);
        $this->seedCopilotTraces($globexProduction, $owner);
    }

    private function seedIntegrations(Deployment $deployment): void
    {
        DeploymentIntegration::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'name' => 'Customer CRM API',
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'type' => 'rest_api',
                'base_url' => 'https://api.example.com',
                'endpoint' => '/health',
                'status' => IntegrationStatus::Connected,
                'config' => ['timeout' => 10, 'retry_count' => 2],
                'secrets' => ['api_key' => 'demo-api-key-not-real'],
            ],
        );

        DeploymentIntegration::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'name' => 'Deployment Webhook',
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'type' => 'webhook',
                'base_url' => null,
                'endpoint' => null,
                'status' => IntegrationStatus::Disconnected,
                'config' => ['events' => ['deployment.updated']],
                'secrets' => ['webhook_secret' => 'demo-webhook-secret-not-real'],
            ],
        );
    }

    private function seedIncidents(Deployment $deployment, User $creator): void
    {
        Incident::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'title' => 'Copilot timeout on knowledge search',
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'created_by' => $creator->id,
                'severity' => IncidentSeverity::High,
                'status' => IncidentStatus::Investigating,
                'source' => IncidentSource::AiFailure,
                'source_reference' => 'trace-1042',
                'description' => 'RAG search exceeded 30s timeout during peak usage. Fallback response served.',
            ],
        );

        Incident::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'title' => 'CRM API connection intermittent',
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'created_by' => $creator->id,
                'severity' => IncidentSeverity::Medium,
                'status' => IncidentStatus::Open,
                'source' => IncidentSource::IntegrationFailure,
                'description' => 'Customer CRM API returned 503 errors during connection test.',
            ],
        );

        Incident::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'title' => 'Eval pass rate regression',
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'created_by' => $creator->id,
                'severity' => IncidentSeverity::Low,
                'status' => IncidentStatus::Resolved,
                'source' => IncidentSource::Manual,
                'description' => 'Evaluation pass rate dropped from 95% to 88% after prompt change.',
                'resolution' => 'Reverted prompt template to v2.1. Pass rate restored.',
                'resolved_at' => now()->subDays(2),
            ],
        );
    }

    private function seedPendingAction(Deployment $deployment, User $requester): void
    {
        AiProposedAction::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'status' => AiActionStatus::Pending,
                'action_type' => AiActionType::UpdateDeploymentStage,
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'payload' => ['stage' => 'validation'],
                'requested_by' => $requester->id,
            ],
        );
    }

    private function seedEvaluationDataset(Deployment $deployment): void
    {
        $dataset = EvaluationDataset::query()->updateOrCreate(
            [
                'deployment_id' => $deployment->id,
                'name' => 'Copilot smoke tests',
            ],
            [
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'description' => 'Core copilot behaviors for portfolio demos.',
            ],
        );

        EvaluationCase::query()->updateOrCreate(
            [
                'evaluation_dataset_id' => $dataset->id,
                'input' => 'What integrations are connected?',
            ],
            [
                'expected_behavior' => 'Lists connected integrations for the deployment.',
                'expected_tools' => ['list_deployment_integrations'],
            ],
        );

        EvaluationCase::query()->updateOrCreate(
            [
                'evaluation_dataset_id' => $dataset->id,
                'input' => 'Summarize recent incidents.',
            ],
            [
                'expected_behavior' => 'Summarizes open and recent incidents with severity.',
                'expected_tools' => ['list_recent_incidents'],
            ],
        );
    }

    private function seedCopilotTraces(Deployment $deployment, User $user): void
    {
        if (CopilotRequestLog::query()->where('deployment_id', $deployment->id)->exists()) {
            return;
        }

        CopilotRequestLog::query()->create([
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'user_id' => $user->id,
            'model' => 'gpt-4.1-mini',
            'question' => 'What is the status of our CRM integration?',
            'tool_names' => ['list_deployment_integrations'],
            'input_tokens' => 420,
            'output_tokens' => 180,
            'rag_used' => false,
            'rag_result_count' => 0,
            'estimated_cost_usd' => 0.0012,
            'latency_ms' => 1240,
            'status' => 'success',
        ]);

        CopilotRequestLog::query()->create([
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'user_id' => $user->id,
            'model' => 'gpt-4.1-mini',
            'question' => 'Search the runbook for rollback procedures.',
            'tool_names' => ['search_knowledge'],
            'input_tokens' => 890,
            'output_tokens' => 340,
            'rag_used' => true,
            'rag_result_count' => 3,
            'estimated_cost_usd' => 0.0028,
            'latency_ms' => 2180,
            'status' => 'success',
        ]);

        CopilotRequestLog::query()->create([
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'user_id' => $user->id,
            'model' => 'gpt-4.1-mini',
            'question' => 'Show AI health metrics for this week.',
            'tool_names' => ['get_ai_health_summary'],
            'input_tokens' => 310,
            'output_tokens' => 95,
            'rag_used' => false,
            'rag_result_count' => 0,
            'estimated_cost_usd' => 0.0009,
            'latency_ms' => 980,
            'status' => 'success',
        ]);
    }
}
