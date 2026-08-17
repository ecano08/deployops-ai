<?php

use App\Enums\DeploymentStage;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('requires authentication for deployment endpoints', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $base = '/api/workspaces/'.$workspace->id.'/customers/'.$customer->id.'/deployments';

    $this->getJson($base)->assertUnauthorized();
    $this->postJson($base, ['name' => 'Rollout'])->assertUnauthorized();
    $this->getJson($base.'/'.$deployment->id)->assertUnauthorized();
    $this->patchJson($base.'/'.$deployment->id, ['name' => 'New'])->assertUnauthorized();
    $this->patchJson($base.'/'.$deployment->id.'/stage', ['stage' => 'build'])->assertUnauthorized();
    $this->deleteJson($base.'/'.$deployment->id)->assertUnauthorized();
});

it('lists deployments for every member role', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Build)->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deployment->id)
        ->assertJsonPath('data.0.stage', 'build');
})->with(['owner', 'admin', 'engineer', 'viewer']);

it('forbids strangers from listing deployments', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments')
        ->assertForbidden();
});

it('allows owners, admins, and engineers to create deployments', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments', [
        'name' => 'Production rollout',
        'description' => 'Initial deployment',
        'stage' => 'integration',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Production rollout')
        ->assertJsonPath('data.stage', 'integration')
        ->assertJsonPath('data.workspace_id', $fixture['workspace']->id)
        ->assertJsonPath('data.customer_id', $customer->id);
})->with(['owner', 'admin', 'engineer']);

it('defaults new deployments to discovery stage', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments', [
        'name' => 'New rollout',
    ])
        ->assertCreated()
        ->assertJsonPath('data.stage', 'discovery');
});

it('forbids viewers and strangers from creating deployments', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments', [
        'name' => 'Blocked',
    ])->assertForbidden();

    expect($customer->deployments()->count())->toBe(0);
})->with(['viewer', 'stranger']);

it('allows owners, admins, and engineers to update and delete deployments', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create(['name' => 'Old']);

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id, [
        'name' => 'Updated',
        'description' => 'Updated description',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated');

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id)
        ->assertNoContent();

    expect(Deployment::query()->find($deployment->id))->toBeNull();
})->with(['owner', 'admin', 'engineer']);

it('forbids viewers and strangers from updating or deleting deployments', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create(['name' => 'Protected']);

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id, [
        'name' => 'Changed',
    ])->assertForbidden();

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id)
        ->assertForbidden();

    expect($deployment->fresh()->name)->toBe('Protected');
})->with(['viewer', 'stranger']);

it('allows owners, admins, and engineers to change deployment stage', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/stage', [
        'stage' => 'validation',
    ])
        ->assertOk()
        ->assertJsonPath('data.stage', 'validation');

    expect($deployment->fresh()->stage)->toBe(DeploymentStage::Validation);
})->with(['owner', 'admin', 'engineer']);

it('forbids viewers and strangers from changing deployment stage', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/stage', [
        'stage' => 'deployed',
    ])->assertForbidden();

    expect($deployment->fresh()->stage)->toBe(DeploymentStage::Discovery);
})->with(['viewer', 'stranger']);

it('returns not found for deployments outside the customer workspace scope', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $foreign = Workspace::factory()->create();
    $foreignCustomer = Customer::factory()->forWorkspace($foreign)->create();
    $foreignDeployment = Deployment::factory()->forCustomer($foreignCustomer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$foreignDeployment->id)
        ->assertNotFound();

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$foreignDeployment->id, [
        'name' => 'Hijacked',
    ])->assertNotFound();

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$foreignDeployment->id.'/stage', [
        'stage' => 'deployed',
    ])->assertNotFound();
});

it('returns not found when a deployment belongs to a different customer in the same workspace', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $otherCustomer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($otherCustomer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id)
        ->assertNotFound();
});

it('stores deployments with matching workspace and customer ownership', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments', [
        'name' => 'Ownership check',
    ])->assertCreated();

    $deployment = Deployment::query()->findOrFail($response->json('data.id'));

    expect($deployment->workspace_id)->toBe($fixture['workspace']->id)
        ->and($deployment->customer_id)->toBe($customer->id)
        ->and($deployment->customer->workspace_id)->toBe($fixture['workspace']->id);
});

it('isolates deployment routes across workspaces', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();
    $foreignCustomer = Customer::factory()->forWorkspace($foreign)->create();
    $foreignDeployment = Deployment::factory()->forCustomer($foreignCustomer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$foreign->id.'/customers/'.$foreignCustomer->id.'/deployments')
        ->assertForbidden();

    $this->postJson('/api/workspaces/'.$foreign->id.'/customers/'.$foreignCustomer->id.'/deployments', [
        'name' => 'Blocked',
    ])->assertForbidden();

    $this->getJson('/api/workspaces/'.$foreign->id.'/customers/'.$foreignCustomer->id.'/deployments/'.$foreignDeployment->id)
        ->assertForbidden();
});

it('validates deployment payloads', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/stage', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stage']);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/stage', [
        'stage' => 'invalid-stage',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stage']);
});

it('grants each role the correct deployment abilities', function () {
    $fixture = createWorkspaceWithRoles();
    $workspace = $fixture['workspace'];
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    expect(Gate::forUser($fixture['owner'])->check('create', [Deployment::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('update', $deployment))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->check('changeStage', $deployment))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->denies('create', [Customer::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->check('view', $deployment))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('update', $deployment))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('changeStage', $deployment))->toBeTrue()
        ->and(Gate::forUser($fixture['stranger'])->denies('view', $deployment))->toBeTrue();
});

it('accepts every deployment stage value', function (string $stage) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id.'/deployments/'.$deployment->id.'/stage', [
        'stage' => $stage,
    ])
        ->assertOk()
        ->assertJsonPath('data.stage', $stage);
})->with(DeploymentStage::values());
