<?php

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\Incident;
use App\Models\Workspace;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'services.openai.api_key' => 'test-openai-key',
        'services.openai.model' => 'gpt-4.1-mini',
    ]);
});

it('requires authentication for incidents', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->getJson(incidentsPath($workspace, $customer, $deployment))
        ->assertUnauthorized();
});

it('allows workspace viewers to list incidents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Incident::factory()->forDeployment($deployment)->create([
        'title' => 'Copilot timeout',
        'source' => IncidentSource::AiFailure,
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(incidentsPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Copilot timeout');
});

it('forbids strangers from listing incidents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->getJson(incidentsPath($fixture['workspace'], $customer, $deployment))
        ->assertForbidden();
});

it('allows engineers to create manual incidents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(incidentsPath($fixture['workspace'], $customer, $deployment), [
        'title' => 'Deployment validation failing',
        'description' => 'Smoke tests are failing after the latest release.',
        'severity' => IncidentSeverity::High->value,
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Deployment validation failing')
        ->assertJsonPath('data.severity', IncidentSeverity::High->value)
        ->assertJsonPath('data.status', IncidentStatus::Open->value)
        ->assertJsonPath('data.source', IncidentSource::Manual->value);
});

it('forbids viewers from creating incidents', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(incidentsPath($fixture['workspace'], $customer, $deployment), [
        'title' => 'Should not create',
        'description' => 'Viewer cannot create incidents.',
        'severity' => IncidentSeverity::Low->value,
    ])->assertForbidden();
});

it('allows engineers to update incident status and resolution', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $incident = Incident::factory()->forDeployment($deployment)->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->patchJson(
        incidentsPath($fixture['workspace'], $customer, $deployment).'/'.$incident->id,
        [
            'status' => IncidentStatus::Resolved->value,
            'root_cause' => 'Expired API credentials.',
            'resolution' => 'Rotated credentials and re-tested integration.',
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.status', IncidentStatus::Resolved->value)
        ->assertJsonPath('data.root_cause', 'Expired API credentials.')
        ->assertJsonPath('data.resolution', 'Rotated credentials and re-tested integration.');
});

it('isolates incidents by tenant', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $otherWorkspace = Workspace::factory()->create();
    $otherCustomer = Customer::factory()->forWorkspace($otherWorkspace)->create();
    $otherDeployment = Deployment::factory()->forCustomer($otherCustomer)->create();
    $foreignIncident = Incident::factory()->forDeployment($otherDeployment)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson(
        incidentsPath($fixture['workspace'], $customer, $deployment).'/'.$foreignIncident->id,
    )->assertNotFound();
});

it('creates an incident when copilot requests fail', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::failedConnection(),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])->assertStatus(503);

    $incident = Incident::query()->first();

    expect($incident)->not->toBeNull()
        ->and($incident->source)->toBe(IncidentSource::AiFailure)
        ->and($incident->deployment_id)->toBe($deployment->id)
        ->and($incident->workspace_id)->toBe($fixture['workspace']->id)
        ->and($incident->title)->toBe('Copilot request failed');
});

it('creates an incident when integration tests fail', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->restApi()->create([
        'base_url' => '',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(
        '/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/integrations/'.$integration->id.'/test',
        [],
    )->assertOk();

    $incident = Incident::query()->first();

    expect($incident)->not->toBeNull()
        ->and($incident->source)->toBe(IncidentSource::IntegrationFailure)
        ->and($incident->deployment_integration_id)->toBe($integration->id);
});

it('does not leak secrets in incident descriptions from ai failures', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $secret = 'sk-supersecretopenaikey1234567890';

    $trace = CopilotRequestLog::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'user_id' => $fixture['owner']->id,
        'customer_id' => $customer->id,
        'deployment_id' => $deployment->id,
        'model' => 'gpt-4.1-mini',
        'question' => 'test',
        'tool_names' => [],
        'latency_ms' => 10,
        'status' => 'failure',
        'error_message' => "Failed with api_key={$secret}",
    ]);

    app(IncidentService::class)->createFromAiFailure($trace, "Failed with api_key={$secret}");

    $incident = Incident::query()->first();

    expect($incident)->not->toBeNull()
        ->and($incident->description)->not->toContain($secret)
        ->and($incident->description)->toContain('[REDACTED]');
});
