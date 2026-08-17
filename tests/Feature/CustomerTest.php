<?php

use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('requires authentication for customer endpoints', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();

    $this->getJson('/api/workspaces/'.$workspace->id.'/customers')->assertUnauthorized();
    $this->postJson('/api/workspaces/'.$workspace->id.'/customers', ['name' => 'Acme'])->assertUnauthorized();
    $this->getJson('/api/workspaces/'.$workspace->id.'/customers/'.$customer->id)->assertUnauthorized();
    $this->patchJson('/api/workspaces/'.$workspace->id.'/customers/'.$customer->id, ['name' => 'New'])->assertUnauthorized();
    $this->deleteJson('/api/workspaces/'.$workspace->id.'/customers/'.$customer->id)->assertUnauthorized();
});

it('lists customers for every member role', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create(['name' => 'Acme Corp']);

    Sanctum::actingAs($fixture[$actorKey]);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $customer->id)
        ->assertJsonPath('data.0.slug', $customer->slug);
})->with(['owner', 'admin', 'engineer', 'viewer']);

it('forbids strangers from listing customers', function () {
    $fixture = createWorkspaceWithRoles();
    Customer::factory()->forWorkspace($fixture['workspace'])->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers')->assertForbidden();
});

it('isolates customer lists across workspaces', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();
    Customer::factory()->forWorkspace($foreign)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$foreign->id.'/customers')->assertForbidden();
});

it('allows owners and admins to create customers', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers', [
        'name' => 'Northwind',
        'description' => 'Primary customer',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Northwind')
        ->assertJsonPath('data.slug', 'northwind')
        ->assertJsonPath('data.description', 'Primary customer')
        ->assertJsonPath('data.workspace_id', $fixture['workspace']->id);
})->with(['owner', 'admin']);

it('forbids engineers, viewers, and strangers from creating customers', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers', [
        'name' => 'Blocked',
    ])->assertForbidden();

    expect($fixture['workspace']->customers()->count())->toBe(0);
})->with(['engineer', 'viewer', 'stranger']);

it('allows owners and admins to update and delete customers', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create(['name' => 'Old Name']);

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id, [
        'name' => 'Updated Name',
        'description' => 'Updated description',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.slug', 'updated-name');

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id)
        ->assertNoContent();

    expect(Customer::query()->find($customer->id))->toBeNull();
})->with(['owner', 'admin']);

it('forbids engineers, viewers, and strangers from updating or deleting customers', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create(['name' => 'Protected']);

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id, [
        'name' => 'Changed',
    ])->assertForbidden();

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$customer->id)
        ->assertForbidden();

    expect($customer->fresh()->name)->toBe('Protected');
})->with(['engineer', 'viewer', 'stranger']);

it('returns not found when accessing a customer from another workspace', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();
    $foreignCustomer = Customer::factory()->forWorkspace($foreign)->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$foreignCustomer->id)
        ->assertNotFound();

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$foreignCustomer->id, [
        'name' => 'Hijacked',
    ])->assertNotFound();

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/customers/'.$foreignCustomer->id)
        ->assertNotFound();
});

it('generates unique workspace-scoped slugs', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers', ['name' => 'Acme'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme');

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers', ['name' => 'Acme'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme-1');
});

it('validates customer payloads', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/customers', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('grants each role the correct customer abilities', function () {
    $fixture = createWorkspaceWithRoles();
    $workspace = $fixture['workspace'];
    $customer = Customer::factory()->forWorkspace($workspace)->create();

    expect(Gate::forUser($fixture['owner'])->check('viewAny', [Customer::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['owner'])->check('create', [Customer::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['owner'])->check('update', $customer))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('create', [Customer::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->check('view', $customer))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->denies('create', [Customer::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->denies('update', $customer))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->check('view', $customer))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('create', [Customer::class, $workspace]))->toBeTrue()
        ->and(Gate::forUser($fixture['stranger'])->denies('view', $customer))->toBeTrue();
});
