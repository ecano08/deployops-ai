<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

/**
 * @return array{
 *     workspace: Workspace,
 *     owner: User,
 *     admin: User,
 *     engineer: User,
 *     viewer: User,
 *     stranger: User
 * }
 */
function createWorkspaceWithRoles(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $engineer = User::factory()->create();
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();

    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $workspace->members()->attach($engineer, ['role' => WorkspaceRole::Engineer->value]);
    $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    return compact('workspace', 'owner', 'admin', 'engineer', 'viewer', 'stranger');
}

it('requires authentication for member endpoints', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $this->getJson('/api/workspaces/'.$workspace->id.'/members')->assertUnauthorized();
    $this->postJson('/api/workspaces/'.$workspace->id.'/members', [
        'email' => $user->email,
        'role' => 'engineer',
    ])->assertUnauthorized();
    $this->patchJson('/api/workspaces/'.$workspace->id.'/members/'.$user->id, [
        'role' => 'admin',
    ])->assertUnauthorized();
    $this->deleteJson('/api/workspaces/'.$workspace->id.'/members/'.$user->id)->assertUnauthorized();
});

it('assigns the owner role when a workspace is created', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/workspaces', ['name' => 'Acme'])
        ->assertCreated()
        ->assertJsonPath('data.current_user_role', 'owner');

    $this->getJson('/api/workspaces/'.$response->json('data.id').'/members')
        ->assertOk()
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.0.id', $user->id);
});

it('lists workspace members with roles for every member role', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    Sanctum::actingAs($fixture[$actorKey]);

    $response = $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/members')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $roles = collect($response->json('data'))
        ->mapWithKeys(fn (array $member) => [$member['email'] => $member['role']]);

    expect($roles[$fixture['owner']->email])->toBe('owner')
        ->and($roles[$fixture['admin']->email])->toBe('admin')
        ->and($roles[$fixture['engineer']->email])->toBe('engineer')
        ->and($roles[$fixture['viewer']->email])->toBe('viewer');
})->with(['owner', 'admin', 'engineer', 'viewer']);

it('forbids strangers from listing members of another workspace', function () {
    $fixture = createWorkspaceWithRoles();
    Sanctum::actingAs($fixture['stranger']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/members')->assertForbidden();
});

it('isolates member lists across workspaces', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$foreign->id.'/members')->assertForbidden();
});

it('allows owners and admins to add an existing user as a member', function (string $actorKey, string $role) {
    $fixture = createWorkspaceWithRoles();
    $newMember = User::factory()->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/members', [
        'email' => $newMember->email,
        'role' => $role,
    ])
        ->assertCreated()
        ->assertJsonPath('data.id', $newMember->id)
        ->assertJsonPath('data.email', $newMember->email)
        ->assertJsonPath('data.role', $role);

    expect($newMember->roleIn($fixture['workspace'])->value)->toBe($role);
})->with([
    'owner adds engineer' => ['owner', 'engineer'],
    'admin adds viewer' => ['admin', 'viewer'],
    'owner adds admin' => ['owner', 'admin'],
]);

it('forbids engineers, viewers, and strangers from adding members', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();
    $newMember = User::factory()->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/members', [
        'email' => $newMember->email,
        'role' => 'engineer',
    ])->assertForbidden();

    expect($newMember->belongsToWorkspace($fixture['workspace']))->toBeFalse();
})->with(['engineer', 'viewer', 'stranger']);

it('does not allow assigning the owner role when adding a member', function () {
    $fixture = createWorkspaceWithRoles();
    $newMember = User::factory()->create();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/members', [
        'email' => $newMember->email,
        'role' => 'owner',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('does not add a user who is already a member', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/members', [
        'email' => $fixture['engineer']->email,
        'role' => 'admin',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('allows adding a user who already belongs to another workspace', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();
    $shared = User::factory()->create();
    $foreign->members()->attach($shared, ['role' => WorkspaceRole::Viewer->value]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/members', [
        'email' => $shared->email,
        'role' => 'engineer',
    ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'engineer');

    expect($shared->roleIn($fixture['workspace']))->toBe(WorkspaceRole::Engineer)
        ->and($shared->roleIn($foreign))->toBe(WorkspaceRole::Viewer);
});

it('allows owners and admins to change assignable member roles', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['engineer']->id, [
        'role' => 'admin',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $fixture['engineer']->id)
        ->assertJsonPath('data.role', 'admin');

    expect($fixture['engineer']->fresh()->roleIn($fixture['workspace']))->toBe(WorkspaceRole::Admin);
})->with(['owner', 'admin']);

it('forbids engineers, viewers, and strangers from changing member roles', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['viewer']->id, [
        'role' => 'engineer',
    ])->assertForbidden();

    expect($fixture['viewer']->fresh()->roleIn($fixture['workspace']))->toBe(WorkspaceRole::Viewer);
})->with(['engineer', 'viewer', 'stranger']);

it('protects the owner from role changes', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['owner']->id, [
        'role' => 'admin',
    ])->assertForbidden();

    expect($fixture['owner']->fresh()->roleIn($fixture['workspace']))->toBe(WorkspaceRole::Owner);
})->with(['owner', 'admin']);

it('does not allow changing a member role to owner', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['engineer']->id, [
        'role' => 'owner',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('allows owners and admins to remove non-owner members', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['viewer']->id)
        ->assertNoContent();

    expect($fixture['viewer']->fresh()->belongsToWorkspace($fixture['workspace']))->toBeFalse();
})->with(['owner', 'admin']);

it('forbids engineers, viewers, and strangers from removing members', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['viewer']->id)
        ->assertForbidden();

    expect($fixture['viewer']->fresh()->belongsToWorkspace($fixture['workspace']))->toBeTrue();
})->with(['engineer', 'viewer', 'stranger']);

it('protects the owner from being removed', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['owner']->id)
        ->assertForbidden();

    expect($fixture['owner']->fresh()->belongsToWorkspace($fixture['workspace']))->toBeTrue()
        ->and($fixture['workspace']->fresh()->owner_id)->toBe($fixture['owner']->id);
})->with(['owner', 'admin']);

it('returns not found when changing or removing a user who is not a member', function () {
    $fixture = createWorkspaceWithRoles();
    $outsider = User::factory()->create();

    Sanctum::actingAs($fixture['owner']);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$outsider->id, [
        'role' => 'admin',
    ])->assertNotFound();

    $this->deleteJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$outsider->id)
        ->assertNotFound();
});

it('does not allow member changes against another workspace', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();
    $foreignMember = User::factory()->create();
    $foreign->members()->attach($foreignMember, ['role' => WorkspaceRole::Engineer->value]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$foreign->id.'/members', [
        'email' => User::factory()->create()->email,
        'role' => 'viewer',
    ])->assertForbidden();

    $this->patchJson('/api/workspaces/'.$foreign->id.'/members/'.$foreignMember->id, [
        'role' => 'admin',
    ])->assertForbidden();

    $this->deleteJson('/api/workspaces/'.$foreign->id.'/members/'.$foreignMember->id)
        ->assertForbidden();

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$foreignMember->id, [
        'role' => 'admin',
    ])->assertNotFound();
});

it('allows engineers and viewers to view the workspace', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.id', $fixture['workspace']->id)
        ->assertJsonPath('data.current_user_role', $actorKey);
})->with(['engineer', 'viewer']);

it('grants each role the correct workspace abilities', function () {
    $fixture = createWorkspaceWithRoles();
    $workspace = $fixture['workspace'];

    expect(Gate::forUser($fixture['owner'])->check('view', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['owner'])->check('operate', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['owner'])->check('addMember', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['owner'])->check('updateMember', [$workspace, $fixture['admin']]))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('view', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('operate', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('addMember', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->check('updateMember', [$workspace, $fixture['engineer']]))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->denies('updateMember', [$workspace, $fixture['owner']]))->toBeTrue()
        ->and(Gate::forUser($fixture['admin'])->denies('removeMember', [$workspace, $fixture['owner']]))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->check('view', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->check('viewMembers', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->check('operate', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->denies('addMember', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->denies('updateMember', [$workspace, $fixture['viewer']]))->toBeTrue()
        ->and(Gate::forUser($fixture['engineer'])->denies('removeMember', [$workspace, $fixture['viewer']]))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->check('view', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->check('viewMembers', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('operate', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['viewer'])->denies('addMember', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['stranger'])->denies('view', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['stranger'])->denies('viewMembers', $workspace))->toBeTrue()
        ->and(Gate::forUser($fixture['stranger'])->denies('operate', $workspace))->toBeTrue();
});

it('validates member payloads', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/members', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'role']);

    $this->patchJson('/api/workspaces/'.$fixture['workspace']->id.'/members/'.$fixture['engineer']->id, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});
