<?php

use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Incident;
use App\Services\CopilotToolExecutor;
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

it('defines observability copilot tools with strict schemas', function () {
    $definitions = app(CopilotToolExecutor::class)->definitions();
    $names = array_column($definitions, 'name');

    expect($names)->toContain('list_recent_incidents', 'get_incident', 'get_ai_health_summary');

    foreach ($definitions as $definition) {
        expect($definition['strict'] ?? null)->toBeTrue()
            ->and($definition['parameters']['additionalProperties'] ?? null)->toBeFalse();
    }
});

it('returns recent incidents through the list_recent_incidents tool', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Incident::factory()->forDeployment($deployment)->create([
        'title' => 'Integration outage',
    ]);

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('list_recent_incidents'))
            ->push(openAiMessageResponse('There is one open incident titled Integration outage.')),
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'What incidents are open?',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['list_recent_incidents']);
});

it('returns ai health summary through the get_ai_health_summary tool', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_ai_health_summary'))
            ->push(openAiMessageResponse('AI health looks stable with zero requests so far.')),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'How healthy is the copilot?',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['get_ai_health_summary']);
});

it('rejects incident ids outside the current deployment', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $foreignIncident = Incident::factory()->forDeployment($otherDeployment)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_incident', json_encode([
                'incident_id' => $foreignIncident->id,
            ])))
            ->push(openAiMessageResponse('That incident is not available in this deployment.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Tell me about that incident.',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['get_incident']);
});

it('records token usage and estimated cost on successful copilot traces', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(array_merge(openAiMessageResponse('All good.'), [
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 30,
                ],
            ])),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Status check',
    ])->assertOk();

    $log = CopilotRequestLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->input_tokens)->toBe(120)
        ->and($log->output_tokens)->toBe(30)
        ->and((float) $log->estimated_cost_usd)->toBeGreaterThan(0);
});

it('does not expose secrets in copilot observability tool responses', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $secret = 'whsec_super_secret_value';

    Incident::factory()->forDeployment($deployment)->create([
        'title' => 'Webhook issue',
        'description' => "Webhook failed with secret {$secret}",
    ]);

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('list_recent_incidents'))
            ->push(openAiMessageResponse('One incident is open.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'List incidents',
    ]);

    $response->assertOk();

    expect($response->getContent())->not->toContain($secret);
});
