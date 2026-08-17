<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('requires authentication to list workspaces', function () {
    $this->getJson('/api/workspaces')->assertUnauthorized();
});

it('requires authentication to create a workspace', function () {
    $this->postJson('/api/workspaces', ['name' => 'Acme'])->assertUnauthorized();
});

it('requires authentication to view a workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->getJson('/api/workspaces/'.$workspace->id)->assertUnauthorized();
});

it('creates a workspace and makes the authenticated user the owner and a member', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/workspaces', ['name' => 'Acme'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Acme')
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.owner_id', $user->id)
        ->assertJsonPath('data.owner.id', $user->id)
        ->assertJsonPath('data.current_user_role', 'owner');

    $workspace = Workspace::query()->where('slug', 'acme')->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->owner_id)->toBe($user->id)
        ->and($user->belongsToWorkspace($workspace))->toBeTrue();
});

it('lists only workspaces the authenticated user belongs to', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $ownWorkspace = Workspace::factory()->create([
        'name' => 'Mine',
        'owner_id' => $user->id,
    ]);
    $foreignWorkspace = Workspace::factory()->create([
        'name' => 'Theirs',
        'owner_id' => $stranger->id,
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/workspaces')->assertOk()->assertJsonCount(1, 'data');

    $ids = $response->json('data.*.id');

    expect($ids)->toContain($ownWorkspace->id)
        ->and($ids)->not->toContain($foreignWorkspace->id);
});

it('allows a user to belong to multiple workspaces', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    $owned = Workspace::factory()->create(['owner_id' => $user->id]);
    $shared = Workspace::factory()->create(['owner_id' => $owner->id]);
    $shared->members()->attach($user);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/workspaces')->assertOk()->assertJsonCount(2, 'data');

    expect($response->json('data.*.id'))->toContain($owned->id, $shared->id);
});

it('allows a member to view a workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/workspaces/'.$workspace->id)
        ->assertOk()
        ->assertJsonPath('data.id', $workspace->id)
        ->assertJsonPath('data.slug', $workspace->slug)
        ->assertJsonPath('data.owner.id', $user->id);
});

it('forbids a user from viewing a workspace they do not belong to', function () {
    $member = User::factory()->create();
    $stranger = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $member->id]);

    Sanctum::actingAs($stranger);

    $this->getJson('/api/workspaces/'.$workspace->id)->assertForbidden();
});

it('forbids a user from viewing another tenant workspace after listing their own', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $ownWorkspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $foreignWorkspace = Workspace::factory()->create(['owner_id' => $other->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/workspaces')
        ->assertOk()
        ->assertJsonPath('data.0.id', $ownWorkspace->id);

    $this->getJson('/api/workspaces/'.$foreignWorkspace->id)->assertForbidden();
});

it('generates unique slugs when workspace names collide', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/workspaces', ['name' => 'Acme'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme');

    $this->postJson('/api/workspaces', ['name' => 'Acme'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'acme-1');
});

it('validates the workspace name', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/workspaces', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});
