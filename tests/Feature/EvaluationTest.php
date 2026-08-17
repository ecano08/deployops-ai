<?php

use App\Enums\DeploymentStage;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\EvaluationCase;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\EvaluationRunResult;
use App\Models\Workspace;
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

it('requires authentication for evaluation datasets', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->getJson(evaluationDatasetsPath($workspace, $customer, $deployment))
        ->assertUnauthorized();
});

it('forbids strangers from managing evaluation datasets', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->postJson(evaluationDatasetsPath($fixture['workspace'], $customer, $deployment), [
        'name' => 'Smoke tests',
    ])->assertForbidden();
});

it('allows engineers to create evaluation datasets and cases', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(evaluationDatasetsPath($fixture['workspace'], $customer, $deployment), [
        'name' => 'Deployment Q&A',
        'description' => 'Core copilot checks',
        'cases' => [
            [
                'input' => 'What stage is this deployment in?',
                'expected_behavior' => 'must: discovery',
                'expected_tools' => ['get_deployment'],
            ],
        ],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'Deployment Q&A')
        ->assertJsonCount(1, 'data.cases');
});

it('forbids viewers from creating evaluation datasets', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(evaluationDatasetsPath($fixture['workspace'], $customer, $deployment), [
        'name' => 'Viewer dataset',
    ])->assertForbidden();
});

it('isolates evaluation datasets by deployment tenant scope', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $otherDeployment).'/'.$dataset->id,
    )->assertNotFound();
});

it('runs evaluation cases and records metrics', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create();
    EvaluationCase::factory()->forDataset($dataset)->create([
        'input' => 'What stage is this deployment in?',
        'expected_behavior' => 'must: discovery',
        'expected_tools' => ['get_deployment'],
    ]);

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_deployment'))
            ->push(openAiMessageResponse('This deployment is in the discovery stage.')),
    ]);

    Sanctum::actingAs($fixture['admin']);

    $response = $this->postJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id.'/runs',
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.metrics.passed_cases', 1)
        ->assertJsonPath('data.metrics.failed_cases', 0)
        ->assertJsonPath('data.results.0.metrics.response_success', true)
        ->assertJsonPath('data.results.0.metrics.expected_tool_usage', true)
        ->assertJsonPath('data.results.0.metrics.groundedness', true)
        ->assertJsonPath('data.results.0.passed', true);

    expect(EvaluationRun::query()->count())->toBe(1);
});

it('marks evaluation cases failed when expected tools are missing', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create();
    EvaluationCase::factory()->forDataset($dataset)->create([
        'input' => 'List integrations',
        'expected_behavior' => 'must: integration',
        'expected_tools' => ['list_deployment_integrations'],
    ]);

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiMessageResponse('There are no integrations configured.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id.'/runs',
    )
        ->assertCreated()
        ->assertJsonPath('data.metrics.failed_cases', 1)
        ->assertJsonPath('data.results.0.metrics.expected_tool_usage', false)
        ->assertJsonPath('data.results.0.passed', false);
});

it('allows viewers to read evaluation runs', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create();
    $case = EvaluationCase::factory()->forDataset($dataset)->create();
    $run = EvaluationRun::factory()->forDataset($dataset, $fixture['owner'])->create();
    EvaluationRunResult::factory()->forRun($run, $case)->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(evaluationRunsPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('allows engineers to update and delete evaluation datasets', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create([
        'name' => 'Original name',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->patchJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id,
        [
            'name' => 'Updated name',
            'description' => 'Updated description',
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated name')
        ->assertJsonPath('data.description', 'Updated description');

    $this->deleteJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id,
    )->assertNoContent();

    expect(EvaluationDataset::query()->find($dataset->id))->toBeNull();
});

it('allows engineers to update and delete evaluation cases', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create();
    $case = EvaluationCase::factory()->forDataset($dataset)->create([
        'input' => 'Original input',
        'expected_behavior' => 'Original behavior',
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->patchJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id.'/cases/'.$case->id,
        [
            'input' => 'Updated input',
            'expected_behavior' => 'Updated behavior',
            'expected_tools' => ['list_deployment_integrations'],
        ],
    )
        ->assertOk()
        ->assertJsonPath('data.input', 'Updated input')
        ->assertJsonPath('data.expected_behavior', 'Updated behavior')
        ->assertJsonPath('data.expected_tools.0', 'list_deployment_integrations');

    $this->deleteJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id.'/cases/'.$case->id,
    )->assertNoContent();

    expect(EvaluationCase::query()->find($case->id))->toBeNull();
});

it('forbids viewers from updating evaluation cases', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $dataset = EvaluationDataset::factory()->forDeployment($deployment)->create();
    $case = EvaluationCase::factory()->forDataset($dataset)->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->patchJson(
        evaluationDatasetsPath($fixture['workspace'], $customer, $deployment).'/'.$dataset->id.'/cases/'.$case->id,
        [
            'input' => 'Viewer update',
        ],
    )->assertForbidden();
});
