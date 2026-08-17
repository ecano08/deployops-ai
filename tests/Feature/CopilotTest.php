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
                'type' => 'server_error',
            ],
        ], 500),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ]);

    $response
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The AI provider is temporarily unavailable.',
        ])
        ->assertJsonStructure(['reference']);

    expect($response->json('message'))->not->toContain('Internal error');

    $log = CopilotRequestLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('failure')
        ->and($log->error_message)->toBe('The AI provider is temporarily unavailable.')
        ->and((string) $response->json('reference'))->toBe((string) $log->id);
});

it('returns a rate limit message when openai throttles the request', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'message' => 'Rate limit reached for requests',
                'type' => 'rate_limit_error',
                'code' => 'rate_limit_exceeded',
            ],
        ], 429),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])
        ->assertStatus(429)
        ->assertJson([
            'message' => 'The AI provider rate limit was reached. Try again shortly.',
        ]);
});

it('returns an authentication message when openai rejects the api key', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'message' => 'Incorrect API key provided: sk-secret1234567890',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ]);

    $response
        ->assertStatus(503)
        ->assertJson([
            'message' => 'OpenAI authentication failed. Check the configured API key.',
        ]);

    expect($response->getContent())->not->toContain('sk-secret1234567890');
});

it('returns an invalid request message when openai rejects the payload', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'message' => 'Invalid schema for function tools[0].',
                'type' => 'invalid_request_error',
            ],
        ], 400),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])
        ->assertStatus(502)
        ->assertJson([
            'message' => 'The AI request was rejected because of an invalid request or tool definition.',
        ]);
});

it('returns a model unavailable message when openai cannot find the model', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'message' => 'The model `gpt-missing` does not exist.',
                'type' => 'invalid_request_error',
                'code' => 'model_not_found',
            ],
        ], 404),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Hello copilot.',
    ])
        ->assertStatus(503)
        ->assertJson([
            'message' => 'The configured AI model is unavailable.',
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

it('does not send previous_response_id on tool continuation rounds', function () {
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

    foreach (Http::recorded() as [$request]) {
        $body = json_decode($request->body(), true);

        expect($body)->toBeArray()
            ->and(array_key_exists('previous_response_id', $body))->toBeFalse();
    }
});

it('continues tool rounds using manual function_call and function_call_output items', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('get_deployment', '{}', 'resp_tool', 'call_abc'))
            ->push(openAiMessageResponse('Deployment summary ready.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Summarize this deployment.',
    ])->assertOk();

    expect(Http::recorded())->toHaveCount(2);

    $secondRequestBody = json_decode(Http::recorded()[1][0]->body(), true);
    $input = $secondRequestBody['input'];

    expect($input)->toBeArray();

    $functionCall = collect($input)->firstWhere('type', 'function_call');
    $functionCallOutput = collect($input)->firstWhere('type', 'function_call_output');

    expect($functionCall)->not->toBeNull()
        ->and($functionCall['call_id'])->toBe('call_abc')
        ->and($functionCall['name'])->toBe('get_deployment')
        ->and($functionCallOutput)->not->toBeNull()
        ->and($functionCallOutput['call_id'])->toBe('call_abc');
});

it('preserves matching call ids when multiple tools are called in one response', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiMultipleToolCallResponse([
                ['call_id' => 'call_alpha', 'name' => 'get_deployment'],
                ['call_id' => 'call_beta', 'name' => 'get_customer'],
            ]))
            ->push(openAiMessageResponse('Deployment and customer details are ready.')),
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Summarize deployment and customer.',
    ])
        ->assertOk()
        ->assertJsonPath('data.tools_used', ['get_deployment', 'get_customer']);

    $secondRequestBody = json_decode(Http::recorded()[1][0]->body(), true);
    $input = $secondRequestBody['input'];

    $functionCalls = collect($input)->where('type', 'function_call')->values();
    $functionCallOutputs = collect($input)->where('type', 'function_call_output')->values();

    expect($functionCalls)->toHaveCount(2)
        ->and($functionCallOutputs)->toHaveCount(2)
        ->and($functionCalls->pluck('call_id')->all())->toBe(['call_alpha', 'call_beta'])
        ->and($functionCallOutputs->pluck('call_id')->all())->toBe(['call_alpha', 'call_beta']);
});

it('fails when the copilot exceeds the maximum number of tool rounds', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $sequence = Http::sequence();

    for ($round = 0; $round < 5; $round++) {
        $sequence->push(openAiToolCallResponse('get_deployment', '{}', "resp_tool_{$round}", "call_{$round}"));
    }

    Http::fake([
        'api.openai.com/v1/responses' => $sequence,
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Keep checking deployment status.',
    ])
        ->assertStatus(502)
        ->assertJson([
            'message' => 'The copilot exceeded the maximum number of tool calls.',
        ]);

    expect(Http::recorded())->toHaveCount(5);
});

it('defines strict json schemas for all copilot tools', function () {
    $definitions = app(CopilotToolExecutor::class)->definitions();

    expect($definitions)->toHaveCount(10);

    foreach ($definitions as $definition) {
        expect($definition['strict'] ?? null)->toBeTrue()
            ->and($definition['name'] ?? null)->toBeString();

        assertStrictCopilotObjectSchema($definition['parameters'], $definition['name']);
    }
});

it('defines search_knowledge with required query and top_k properties', function () {
    $definitions = app(CopilotToolExecutor::class)->definitions();
    $searchKnowledge = collect($definitions)->firstWhere('name', 'search_knowledge');

    expect($searchKnowledge)->not->toBeNull()
        ->and(array_keys($searchKnowledge['parameters']['properties']))->toBe(['query', 'top_k'])
        ->and($searchKnowledge['parameters']['required'])->toBe(['query', 'top_k'])
        ->and($searchKnowledge['parameters']['properties']['top_k']['type'])->toBe('integer');
});

/**
 * @param  array<string, mixed>  $schema
 */
function assertStrictCopilotObjectSchema(array $schema, string $context): void
{
    expect($schema['type'] ?? null)->toBe('object')
        ->and($schema['additionalProperties'] ?? null)->toBeFalse()
        ->and(isset($schema['required']))->toBeTrue()
        ->and($schema['required'])->toBeArray();

    $properties = $schema['properties'] ?? null;

    expect($properties)->not->toBeNull();

    $propertyKeys = match (true) {
        $properties instanceof stdClass => array_keys((array) $properties),
        is_array($properties) => array_keys($properties),
        default => [],
    };

    $required = $schema['required'];

    foreach ($propertyKeys as $propertyKey) {
        expect($required)->toContain($propertyKey);
    }

    foreach ($required as $requiredKey) {
        expect($propertyKeys)->toContain($requiredKey);
    }

    foreach ($propertyKeys as $propertyKey) {
        $propertySchema = is_array($properties) ? $properties[$propertyKey] : ((array) $properties)[$propertyKey];

        if (is_array($propertySchema) && ($propertySchema['type'] ?? null) === 'object') {
            assertStrictCopilotObjectSchema($propertySchema, "{$context}.{$propertyKey}");
        }
    }
}

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
