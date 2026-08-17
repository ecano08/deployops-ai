<?php

use App\Models\CopilotRequestLog;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\IntegrationActivity;
use App\Models\Workspace;
use App\Services\CopilotToolExecutor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'services.openai.api_key' => 'test-openai-key',
        'services.openai.model' => 'gpt-4.1-mini',
    ]);
});

it('requires authentication for the copilot endpoint', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->postJson(copilotPath($workspace, $customer, $deployment), [
        'message' => 'What stage is this deployment in?',
    ])->assertUnauthorized();
});

it('forbids strangers from using the copilot', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Summarize this deployment.',
    ])->assertForbidden();
});

it('allows workspace viewers to ask the copilot', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiMessageResponse('The deployment is in discovery.')),
    ]);

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'What stage is this deployment in?',
    ])
        ->assertOk()
        ->assertJsonPath('data.answer', 'The deployment is in discovery.')
        ->assertJsonPath('data.tools_used', []);
});

it('executes read-only tools and returns the final answer', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create(['name' => 'Acme Corp']);
    $deployment = Deployment::factory()->forCustomer($customer)->create(['name' => 'Production Rollout']);
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->restApi()->create([
        'name' => 'Billing API',
        'secrets' => ['api_key' => 'super-secret-key'],
    ]);

    IntegrationActivity::factory()->forIntegration($integration)->create([
        'message' => 'Connection test succeeded.',
    ]);

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('list_deployment_integrations'))
            ->push(openAiMessageResponse('You have one integration named Billing API.')),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'What integrations are configured?',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.answer', 'You have one integration named Billing API.')
        ->assertJsonPath('data.tools_used', ['list_deployment_integrations']);

    Http::assertSentCount(2);

    $log = CopilotRequestLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->workspace_id)->toBe($fixture['workspace']->id)
        ->and($log->deployment_id)->toBe($deployment->id)
        ->and($log->model)->toBe('gpt-4.1-mini')
        ->and($log->tool_names)->toBe(['list_deployment_integrations'])
        ->and($log->status)->toBe('success')
        ->and($log->latency_ms)->toBeGreaterThanOrEqual(0);

    expect($response->getContent())->not->toContain('super-secret-key');
});

it('rejects integration ids outside the current deployment', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $foreignIntegration = DeploymentIntegration::factory()->forDeployment($otherDeployment)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_integration_status', json_encode([
                'integration_id' => $foreignIntegration->id,
            ])))
            ->push(openAiMessageResponse('That integration is not available in this deployment.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'What is the integration status?',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['get_integration_status']);
});

it('never exposes integration secrets through tool execution', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->webhook()->create([
        'secrets' => ['webhook_secret' => 'whsec_super_secret'],
    ]);

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_integration_status', json_encode([
                'integration_id' => $integration->id,
            ])))
            ->push(openAiMessageResponse('Webhook integration is disconnected.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Is the webhook configured?',
    ]);

    $response->assertOk();

    expect($response->json('data.tools_used'))->toContain('get_integration_status');
    expect($response->getContent())->not->toContain('whsec_super_secret');
});

it('returns a clean error when openai times out', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::failedConnection(),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The AI service timed out. Please try again.',
        ]);

    $log = CopilotRequestLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('failure')
        ->and($log->error_message)->toBe('The AI service timed out. Please try again.');
});

it('returns a clean error when openai responds with an upstream failure', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'message' => 'Internal error',
            ],
        ], 500),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The AI service returned an error.',
        ]);
});

it('returns unavailable when the openai api key is missing', function () {
    config(['services.openai.api_key' => null]);

    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])
        ->assertStatus(503)
        ->assertJson([
            'message' => 'OpenAI API key is not configured.',
        ]);
});

it('validates the copilot message', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

it('sends store false on every openai responses request', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_deployment'))
            ->push(openAiMessageResponse('Deployment summary ready.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Summarize this deployment.',
    ])->assertOk();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return is_array($body) && ($body['store'] ?? null) === false;
    });

    expect(Http::recorded()->count())->toBe(2);
});

it('defines strict json schemas for all copilot tools', function () {
    $definitions = app(CopilotToolExecutor::class)->definitions();

    expect($definitions)->toHaveCount(6);

    foreach ($definitions as $definition) {
        expect($definition['strict'] ?? null)->toBeTrue()
            ->and($definition['parameters']['additionalProperties'] ?? null)->toBeFalse();
    }
});

it('rejects unexpected tool arguments server-side', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_deployment', json_encode([
                'workspace_id' => $fixture['workspace']->id,
            ])))
            ->push(openAiMessageResponse('I could not read extra deployment arguments.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Summarize this deployment.',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['get_deployment']);
});

it('redacts sensitive content from copilot request logs', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $secret = 'sk-supersecretopenaikey1234567890';

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiMessageResponse('I cannot help with secrets.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => "My api_key={$secret} is failing.",
    ])->assertOk();

    $log = CopilotRequestLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->question)->not->toContain($secret)
        ->and($log->question)->toContain('[REDACTED]');
});
