<?php

use App\Models\AiToolCallTrace;
use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('requires authentication for ai health metrics', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->getJson(aiHealthPath($workspace, $customer, $deployment))
        ->assertUnauthorized();
});

it('returns ai health metrics for deployment members', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    CopilotRequestLog::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'user_id' => $fixture['owner']->id,
        'customer_id' => $customer->id,
        'deployment_id' => $deployment->id,
        'model' => 'gpt-4.1-mini',
        'question' => 'What is the deployment stage?',
        'tool_names' => ['get_deployment'],
        'input_tokens' => 100,
        'output_tokens' => 50,
        'rag_used' => false,
        'rag_result_count' => 0,
        'estimated_cost_usd' => 0.00012,
        'latency_ms' => 250,
        'status' => 'success',
    ]);

    CopilotRequestLog::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'user_id' => $fixture['owner']->id,
        'customer_id' => $customer->id,
        'deployment_id' => $deployment->id,
        'model' => 'gpt-4.1-mini',
        'question' => 'Summarize failures',
        'tool_names' => [],
        'input_tokens' => 80,
        'output_tokens' => 20,
        'rag_used' => true,
        'rag_result_count' => 2,
        'estimated_cost_usd' => 0.00008,
        'latency_ms' => 400,
        'status' => 'failure',
        'error_message' => 'The AI service timed out.',
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->getJson(aiHealthPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.request_count', 2)
        ->assertJsonPath('data.failure_count', 1)
        ->assertJsonPath('data.failure_rate', 0.5)
        ->assertJsonPath('data.total_input_tokens', 180)
        ->assertJsonPath('data.total_output_tokens', 70)
        ->assertJsonPath('data.rag_request_count', 1);
});

it('forbids strangers from viewing ai health metrics', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->getJson(aiHealthPath($fixture['workspace'], $customer, $deployment))
        ->assertForbidden();
});

it('lists recent ai traces without leaking secrets', function () {
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
        'question' => "api_key={$secret} failed",
        'tool_names' => ['search_knowledge'],
        'input_tokens' => 42,
        'output_tokens' => 21,
        'rag_used' => true,
        'rag_result_count' => 3,
        'estimated_cost_usd' => 0.00005,
        'latency_ms' => 120,
        'status' => 'success',
    ]);

    AiToolCallTrace::factory()->forTrace($trace)->create([
        'tool_name' => 'search_knowledge',
        'metadata' => ['result_count' => 3],
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->getJson(aiTracesPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.0.id', $trace->id)
        ->assertJsonPath('data.0.rag_used', true)
        ->assertJsonPath('data.0.input_tokens', 42)
        ->assertJsonPath('data.0.tool_call_traces.0.tool_name', 'search_knowledge');

    expect($response->getContent())->not->toContain($secret)
        ->and($response->json('data.0.question_preview'))->toContain('[REDACTED]');
});

it('isolates ai traces by tenant', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $otherWorkspace = Workspace::factory()->create();
    $otherCustomer = Customer::factory()->forWorkspace($otherWorkspace)->create();
    $otherDeployment = Deployment::factory()->forCustomer($otherCustomer)->create();

    $foreignTrace = CopilotRequestLog::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $fixture['owner']->id,
        'customer_id' => $otherCustomer->id,
        'deployment_id' => $otherDeployment->id,
        'model' => 'gpt-4.1-mini',
        'question' => 'foreign trace',
        'tool_names' => [],
        'latency_ms' => 10,
        'status' => 'success',
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->getJson(aiTracesPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson(
        aiTracesPath($fixture['workspace'], $customer, $deployment).'/'.$foreignTrace->id,
    )->assertNotFound();
});

it('counts tool failures in ai health metrics', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $trace = CopilotRequestLog::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'user_id' => $fixture['owner']->id,
        'customer_id' => $customer->id,
        'deployment_id' => $deployment->id,
        'model' => 'gpt-4.1-mini',
        'question' => 'test',
        'tool_names' => ['get_integration_status'],
        'latency_ms' => 50,
        'status' => 'success',
    ]);

    AiToolCallTrace::factory()->forTrace($trace)->create([
        'status' => 'failure',
        'metadata' => ['error' => 'Integration not found in this deployment.'],
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->getJson(aiHealthPath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonPath('data.tool_failure_count', 1);
});
