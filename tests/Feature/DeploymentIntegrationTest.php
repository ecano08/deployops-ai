<?php

use App\Enums\IntegrationStatus;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\IntegrationActivity;
use App\Models\Workspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

function integrationBasePath(Workspace $workspace, Customer $customer, Deployment $deployment): string
{
    return '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/integrations';
}

function postSignedWebhook(
    DeploymentIntegration $integration,
    string $payload,
    ?string $timestamp = null,
    ?string $signatureOverride = null,
): TestResponse {
    $timestamp ??= (string) time();
    $secret = $integration->webhookSecret() ?? 'whsec_test';
    $signature = $signatureOverride ?? ('sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret));

    return test()->call(
        'POST',
        '/api/webhooks/integrations/'.$integration->id,
        [],
        [],
        [],
        [
            'HTTP_X-Integration-Timestamp' => $timestamp,
            'HTTP_X-Integration-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $payload,
    );
}

it('requires authentication for integration endpoints', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->create();

    $base = integrationBasePath($workspace, $customer, $deployment);

    $this->getJson($base)->assertUnauthorized();
    $this->postJson($base, ['name' => 'API', 'type' => 'rest_api'])->assertUnauthorized();
    $this->getJson($base.'/'.$integration->id)->assertUnauthorized();
    $this->patchJson($base.'/'.$integration->id, ['name' => 'New'])->assertUnauthorized();
    $this->deleteJson($base.'/'.$integration->id)->assertUnauthorized();
    $this->postJson($base.'/'.$integration->id.'/test')->assertUnauthorized();
    $this->getJson($base.'/'.$integration->id.'/activities')->assertUnauthorized();
});

it('lists integrations for every member role', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->restApi()->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->getJson(integrationBasePath($fixture['workspace'], $customer, $deployment))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $integration->id)
        ->assertJsonMissingPath('data.0.secrets')
        ->assertJsonMissingPath('data.0.api_key');
})->with(['owner', 'admin', 'engineer', 'viewer']);

it('forbids strangers from listing integrations', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    DeploymentIntegration::factory()->forDeployment($deployment)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->getJson(integrationBasePath($fixture['workspace'], $customer, $deployment))
        ->assertForbidden();
});

it('allows owners, admins, and engineers to create integrations', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson(integrationBasePath($fixture['workspace'], $customer, $deployment), [
        'type' => 'rest_api',
        'name' => 'Payments API',
        'base_url' => 'https://api.example.com',
        'endpoint' => '/health',
        'config' => ['timeout' => 5],
        'api_key' => 'secret-api-key',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Payments API')
        ->assertJsonPath('data.type', 'rest_api')
        ->assertJsonPath('data.status', 'disconnected')
        ->assertJsonPath('data.has_api_key', true)
        ->assertJsonMissingPath('data.api_key')
        ->assertJsonMissingPath('data.secrets');

    $integration = DeploymentIntegration::query()->first();
    expect($integration->apiKey())->toBe('secret-api-key');
})->with(['owner', 'admin', 'engineer']);

it('forbids viewers and strangers from creating integrations', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson(integrationBasePath($fixture['workspace'], $customer, $deployment), [
        'type' => 'rest_api',
        'name' => 'Blocked',
        'base_url' => 'https://api.example.com',
    ])->assertForbidden();

    expect(DeploymentIntegration::query()->count())->toBe(0);
})->with(['viewer', 'stranger']);

it('allows owners, admins, and engineers to update, test, and delete integrations', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->webhook()->create([
        'name' => 'Old webhook',
    ]);

    Sanctum::actingAs($fixture[$actorKey]);

    $base = integrationBasePath($fixture['workspace'], $customer, $deployment);

    $this->patchJson($base.'/'.$integration->id, [
        'name' => 'Updated webhook',
        'webhook_secret' => 'new-webhook-secret',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated webhook')
        ->assertJsonPath('data.has_webhook_secret', true)
        ->assertJsonMissingPath('data.webhook_secret');

    expect($integration->fresh()->webhookSecret())->toBe('new-webhook-secret');

    $this->postJson($base.'/'.$integration->id.'/test')
        ->assertOk()
        ->assertJsonPath('data.success', true)
        ->assertJsonPath('data.status', 'connected');

    $this->deleteJson($base.'/'.$integration->id)->assertNoContent();
    expect(DeploymentIntegration::query()->find($integration->id))->toBeNull();
})->with(['owner', 'admin', 'engineer']);

it('forbids viewers and strangers from updating, testing, or deleting integrations', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->webhook()->create([
        'name' => 'Protected',
    ]);

    Sanctum::actingAs($fixture[$actorKey]);

    $base = integrationBasePath($fixture['workspace'], $customer, $deployment);

    $this->patchJson($base.'/'.$integration->id, ['name' => 'Changed'])->assertForbidden();
    $this->postJson($base.'/'.$integration->id.'/test')->assertForbidden();
    $this->deleteJson($base.'/'.$integration->id)->assertForbidden();

    expect($integration->fresh()->name)->toBe('Protected');
})->with(['viewer', 'stranger']);

it('stores secrets encrypted and never exposes them in api responses', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson(integrationBasePath($fixture['workspace'], $customer, $deployment), [
        'type' => 'rest_api',
        'name' => 'Secure API',
        'base_url' => 'https://api.example.com',
        'api_key' => 'super-secret-key',
    ])->assertCreated();

    $integration = DeploymentIntegration::query()->findOrFail($response->json('data.id'));
    $rawSecrets = $integration->getRawOriginal('secrets');

    expect($rawSecrets)->not->toContain('super-secret-key')
        ->and($integration->apiKey())->toBe('super-secret-key');

    $showResponse = $this->getJson(integrationBasePath($fixture['workspace'], $customer, $deployment).'/'.$integration->id)
        ->assertOk();

    expect(json_encode($showResponse->json()))->not->toContain('super-secret-key');
});

it('tests rest api connection success and failure without leaking secrets', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->restApi()->withApiKey('token-123')->create([
        'base_url' => 'https://api.example.com',
        'endpoint' => 'health',
    ]);

    Sanctum::actingAs($fixture['owner']);

    Http::fake([
        'api.example.com/*' => Http::sequence()
            ->push(['ok' => true], 200)
            ->push(['error' => true], 503),
    ]);

    $base = integrationBasePath($fixture['workspace'], $customer, $deployment);

    $successResponse = $this->postJson($base.'/'.$integration->id.'/test')
        ->assertOk()
        ->assertJsonPath('data.success', true)
        ->assertJsonPath('data.metadata.http_status', 200)
        ->assertJsonMissingPath('data.metadata.api_key');

    expect(json_encode($successResponse->json()))->not->toContain('token-123')
        ->and($integration->fresh()->status)->toBe(IntegrationStatus::Connected);

    $failureResponse = $this->postJson($base.'/'.$integration->id.'/test')
        ->assertOk()
        ->assertJsonPath('data.success', false)
        ->assertJsonPath('data.metadata.http_status', 503);

    expect($failureResponse->json('data.success'))->toBeFalse()
        ->and($integration->fresh()->status)->toBe(IntegrationStatus::Error);
});

it('records integration activity for connection tests', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->webhook()->create();

    Sanctum::actingAs($fixture['owner']);

    $base = integrationBasePath($fixture['workspace'], $customer, $deployment);

    $this->postJson($base.'/'.$integration->id.'/test')->assertOk();

    $this->getJson($base.'/'.$integration->id.'/activities')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'test_connection')
        ->assertJsonPath('data.0.status', 'success')
        ->assertJsonMissingPath('data.0.metadata.api_key');
});

it('accepts webhook events with valid signatures and rejects invalid ones', function () {
    $integration = DeploymentIntegration::factory()->webhook()->create([
        'secrets' => ['webhook_secret' => 'whsec_test'],
    ]);

    $payload = json_encode(['event' => 'deployment.updated']);

    postSignedWebhook($integration, $payload)
        ->assertOk()
        ->assertJsonPath('data.received', true);

    expect($integration->fresh()->status)->toBe(IntegrationStatus::Connected)
        ->and(IntegrationActivity::query()->where('deployment_integration_id', $integration->id)->count())->toBe(1);

    postSignedWebhook($integration, $payload, signatureOverride: 'invalid-signature')
        ->assertUnauthorized();

    expect($integration->fresh()->status)->toBe(IntegrationStatus::Connected)
        ->and(IntegrationActivity::query()->where('deployment_integration_id', $integration->id)->count())->toBe(1);
});

it('rejects webhook events for non-webhook integrations', function () {
    $integration = DeploymentIntegration::factory()->restApi()->create();

    $this->postJson('/api/webhooks/integrations/'.$integration->id, ['event' => 'test'])
        ->assertStatus(422);
});

it('returns not found for integrations outside deployment customer scope', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $otherCustomer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($otherCustomer)->create();
    $foreignIntegration = DeploymentIntegration::factory()->forDeployment($otherDeployment)->create();

    Sanctum::actingAs($fixture['owner']);

    $base = integrationBasePath($fixture['workspace'], $customer, $deployment);

    $this->getJson($base.'/'.$foreignIntegration->id)->assertNotFound();
    $this->patchJson($base.'/'.$foreignIntegration->id, ['name' => 'Hijacked'])->assertNotFound();
    $this->postJson($base.'/'.$foreignIntegration->id.'/test')->assertNotFound();
});

it('isolates integration routes across workspaces', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $foreign = Workspace::factory()->create();
    $foreignCustomer = Customer::factory()->forWorkspace($foreign)->create();
    $foreignDeployment = Deployment::factory()->forCustomer($foreignCustomer)->create();
    $foreignIntegration = DeploymentIntegration::factory()->forDeployment($foreignDeployment)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson(integrationBasePath($foreign, $foreignCustomer, $foreignDeployment))
        ->assertForbidden();

    $this->postJson(integrationBasePath($foreign, $foreignCustomer, $foreignDeployment), [
        'type' => 'webhook',
        'name' => 'Blocked',
    ])->assertForbidden();

    $this->getJson(
        integrationBasePath($fixture['workspace'], $customer, $deployment).'/'.$foreignIntegration->id,
    )->assertNotFound();
});

it('grants each role the correct integration abilities', function () {
    $fixture = createWorkspaceWithRoles();
    $workspace = $fixture['workspace'];
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->create();

    expect(Gate::forUser($fixture['owner'])->check('create', [DeploymentIntegration::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('update', $integration))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->check('test', $integration))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->check('view', $integration))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('update', $integration))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('test', $integration))->toBeTrue()
        ->and(Gate::forUser($fixture['stranger'])->denies('view', $integration))->toBeTrue();
});

it('validates integration payloads', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['owner']);

    $base = integrationBasePath($fixture['workspace'], $customer, $deployment);

    $this->postJson($base, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type']);

    $this->postJson($base, [
        'type' => 'rest_api',
        'name' => 'Missing URL',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url']);
});

it('rejects private network base URLs when creating integrations', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(integrationBasePath($fixture['workspace'], $customer, $deployment), [
        'type' => 'rest_api',
        'name' => 'Internal API',
        'base_url' => 'http://127.0.0.1',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url']);
});

it('blocks rest api connection tests to private network URLs', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $integration = DeploymentIntegration::factory()->forDeployment($deployment)->restApi()->create([
        'base_url' => 'http://127.0.0.1',
        'endpoint' => 'health',
    ]);

    Sanctum::actingAs($fixture['owner']);

    Http::fake();

    $this->postJson(integrationBasePath($fixture['workspace'], $customer, $deployment).'/'.$integration->id.'/test')
        ->assertOk()
        ->assertJsonPath('data.success', false)
        ->assertJsonPath('data.metadata.result', 'blocked_url');

    Http::assertNothingSent();
});

it('rejects replayed webhook events with the same signature', function () {
    Cache::flush();

    $integration = DeploymentIntegration::factory()->webhook()->create([
        'secrets' => ['webhook_secret' => 'whsec_test'],
    ]);

    $payload = json_encode(['event' => 'deployment.updated']);
    $timestamp = (string) time();

    postSignedWebhook($integration, $payload, $timestamp)->assertOk();
    postSignedWebhook($integration, $payload, $timestamp)->assertConflict();

    expect(IntegrationActivity::query()->where('deployment_integration_id', $integration->id)->count())->toBe(1);
});

it('rejects webhook events with stale timestamps', function () {
    $integration = DeploymentIntegration::factory()->webhook()->create([
        'secrets' => ['webhook_secret' => 'whsec_test'],
    ]);

    $payload = json_encode(['event' => 'deployment.updated']);
    $staleTimestamp = (string) (time() - 600);

    postSignedWebhook($integration, $payload, $staleTimestamp)->assertUnauthorized();
});

it('does not write integration activity rows for invalid webhook signatures', function () {
    $integration = DeploymentIntegration::factory()->webhook()->create([
        'secrets' => ['webhook_secret' => 'whsec_test'],
        'status' => IntegrationStatus::Connected,
    ]);

    $payload = json_encode(['event' => 'deployment.updated']);

    postSignedWebhook($integration, $payload, signatureOverride: 'invalid-signature')
        ->assertUnauthorized();

    expect(IntegrationActivity::query()->where('deployment_integration_id', $integration->id)->count())->toBe(0)
        ->and($integration->fresh()->status)->toBe(IntegrationStatus::Connected);
});

it('rate limits webhook requests', function () {
    RateLimiter::clearResolvedInstances();

    RateLimiter::for('integration-webhooks', function (Request $request) {
        return Limit::perMinute(2)->by('integration-webhook-test');
    });

    $integration = DeploymentIntegration::factory()->webhook()->create([
        'secrets' => ['webhook_secret' => 'whsec_test'],
    ]);

    $payload = json_encode(['event' => 'rate.limit']);

    postSignedWebhook($integration, $payload)->assertOk();
    postSignedWebhook($integration, json_encode(['event' => 'rate.limit.2']))->assertOk();
    postSignedWebhook($integration, json_encode(['event' => 'rate.limit.3']))->assertTooManyRequests();
});
