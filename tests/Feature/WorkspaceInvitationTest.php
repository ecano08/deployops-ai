<?php

use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('requires authentication for workspace invitation endpoints', function () {
    $workspace = Workspace::factory()->create();

    $this->getJson('/api/workspaces/'.$workspace->id.'/invitations')->assertUnauthorized();
    $this->postJson('/api/workspaces/'.$workspace->id.'/invitations', [
        'email' => 'new@example.com',
        'role' => 'engineer',
    ])->assertUnauthorized();
});

it('adds an existing user to the workspace when invited', function (string $actorKey, string $role) {
    $fixture = createWorkspaceWithRoles();
    $newMember = User::factory()->create();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => $newMember->email,
        'role' => $role,
    ])
        ->assertCreated()
        ->assertJsonPath('data.id', $newMember->id)
        ->assertJsonPath('data.email', $newMember->email)
        ->assertJsonPath('data.role', $role)
        ->assertJsonMissingPath('data.status');

    expect($newMember->roleIn($fixture['workspace'])->value)->toBe($role);
    expect(WorkspaceInvitation::query()->where('email', $newMember->email)->exists())->toBeFalse();
})->with([
    'owner adds engineer' => ['owner', 'engineer'],
    'admin adds viewer' => ['admin', 'viewer'],
    'owner adds admin' => ['owner', 'admin'],
]);

it('creates a pending invitation when the email does not belong to a user', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $response = $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => 'new@example.com',
        'role' => 'engineer',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.role', 'engineer')
        ->assertJsonPath('data.status', 'pending');

    $invitation = WorkspaceInvitation::query()->where('email', 'new@example.com')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->workspace_id)->toBe($fixture['workspace']->id)
        ->and($invitation->role)->toBe(WorkspaceRole::Engineer)
        ->and($invitation->status)->toBe(WorkspaceInvitationStatus::Pending)
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($invitation->token)->toHaveLength(64)
        ->and($response->json('data.invitation_url'))->toEndWith('/invitations/'.$invitation->token)
        ->and($response->json('data'))->not->toHaveKey('token');

    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('accepts a valid invitation and creates the user with workspace membership', function () {
    $fixture = createWorkspaceWithRoles();
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'invited@example.com',
        'role' => WorkspaceRole::Engineer,
    ]);

    $this->getJson('/api/invitations/'.$invitation->token)
        ->assertOk()
        ->assertJsonPath('data.email', 'invited@example.com')
        ->assertJsonPath('data.role', 'engineer')
        ->assertJsonPath('data.workspace.name', $fixture['workspace']->name)
        ->assertJsonMissingPath('data.invitation_url');

    $this->postJson('/api/invitations/'.$invitation->token.'/accept', [
        'name' => 'Invited Engineer',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Invited Engineer')
        ->assertJsonPath('data.email', 'invited@example.com')
        ->assertJsonStructure(['token']);

    $user = User::query()->where('email', 'invited@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Invited Engineer')
        ->and($user->roleIn($fixture['workspace']))->toBe(WorkspaceRole::Engineer)
        ->and($invitation->fresh()->status)->toBe(WorkspaceInvitationStatus::Accepted)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects expired, invalid, and reused invitation tokens', function () {
    $fixture = createWorkspaceWithRoles();
    $expired = WorkspaceInvitation::factory()->expired()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'expired@example.com',
    ]);
    $accepted = WorkspaceInvitation::factory()->accepted()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'accepted@example.com',
    ]);
    $valid = WorkspaceInvitation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'reuse@example.com',
        'role' => WorkspaceRole::Viewer,
    ]);

    $this->getJson('/api/invitations/missing-token')->assertNotFound();
    $this->postJson('/api/invitations/missing-token/accept', [
        'name' => 'Nobody',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->getJson('/api/invitations/'.$expired->token)->assertGone();
    $this->postJson('/api/invitations/'.$expired->token.'/accept', [
        'name' => 'Expired User',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertGone();

    $this->getJson('/api/invitations/'.$accepted->token)->assertGone();

    $this->postJson('/api/invitations/'.$valid->token.'/accept', [
        'name' => 'First Accept',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated();

    $this->getJson('/api/invitations/'.$valid->token)->assertGone();
    $this->postJson('/api/invitations/'.$valid->token.'/accept', [
        'name' => 'Second Accept',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertGone();

    expect(User::query()->where('email', 'expired@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('name', 'Second Accept')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'reuse@example.com')->count())->toBe(1);
});

it('forbids engineers, viewers, and strangers from creating invitations', function (string $actorKey) {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture[$actorKey]);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => 'new@example.com',
        'role' => 'engineer',
    ])->assertForbidden();

    expect(WorkspaceInvitation::query()->where('email', 'new@example.com')->exists())->toBeFalse();
})->with(['engineer', 'viewer', 'stranger']);

it('does not allow inviting someone as owner', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => 'new@example.com',
        'role' => 'owner',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);

    expect(WorkspaceInvitation::query()->where('email', 'new@example.com')->exists())->toBeFalse();
});

it('isolates invitations across workspaces', function () {
    $fixture = createWorkspaceWithRoles();
    $foreign = Workspace::factory()->create();
    $foreignInvitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $foreign->id,
        'invited_by' => $foreign->owner_id,
        'email' => 'foreign@example.com',
        'role' => WorkspaceRole::Viewer,
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$foreign->id.'/invitations')->assertForbidden();
    $this->postJson('/api/workspaces/'.$foreign->id.'/invitations', [
        'email' => 'isolated@example.com',
        'role' => 'viewer',
    ])->assertForbidden();

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => 'isolated@example.com',
        'role' => 'viewer',
    ])->assertCreated();

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'isolated@example.com');

    expect(WorkspaceInvitation::query()->where('email', 'isolated@example.com')->value('workspace_id'))
        ->toBe($fixture['workspace']->id)
        ->and($foreignInvitation->fresh()->workspace_id)->toBe($foreign->id);

    $this->postJson('/api/invitations/'.$foreignInvitation->token.'/accept', [
        'name' => 'Foreign Invitee',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated();

    $invitee = User::query()->where('email', 'foreign@example.com')->first();

    expect($invitee->belongsToWorkspace($foreign))->toBeTrue()
        ->and($invitee->belongsToWorkspace($fixture['workspace']))->toBeFalse();
});

it('lists pending invitations for workspace members and exposes copy links to managers', function () {
    $fixture = createWorkspaceWithRoles();
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'pending@example.com',
        'role' => WorkspaceRole::Admin,
    ]);
    WorkspaceInvitation::factory()->expired()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'expired@example.com',
    ]);
    WorkspaceInvitation::factory()->accepted()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'done@example.com',
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'pending@example.com')
        ->assertJsonPath('data.0.role', 'admin')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.invitation_url', $invitation->invitationUrl());

    Sanctum::actingAs($fixture['engineer']);

    $this->getJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'pending@example.com')
        ->assertJsonMissingPath('data.0.invitation_url');
});

it('refreshes a pending invitation instead of creating a duplicate', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $first = $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => 'repeat@example.com',
        'role' => 'viewer',
    ])->assertCreated();

    $originalToken = WorkspaceInvitation::query()->where('email', 'repeat@example.com')->value('token');

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => 'repeat@example.com',
        'role' => 'engineer',
    ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'engineer')
        ->assertJsonPath('data.status', 'pending');

    $invitation = WorkspaceInvitation::query()->where('email', 'repeat@example.com')->first();

    expect(WorkspaceInvitation::query()->where('email', 'repeat@example.com')->count())->toBe(1)
        ->and($invitation->role)->toBe(WorkspaceRole::Engineer)
        ->and($invitation->token)->not->toBe($originalToken);

    $this->getJson('/api/invitations/'.$originalToken)->assertNotFound();
    $this->getJson('/api/invitations/'.$invitation->token)->assertOk();
    expect($first->json('data.id'))->toBe($invitation->id);
});

it('does not join a workspace when registering with an invited email', function () {
    $fixture = createWorkspaceWithRoles();
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'new@example.com',
        'role' => WorkspaceRole::Engineer,
    ]);

    $this->postJson('/api/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated();

    $user = User::query()->where('email', 'new@example.com')->first();

    expect($user->belongsToWorkspace($fixture['workspace']))->toBeFalse()
        ->and($invitation->fresh()->status)->toBe(WorkspaceInvitationStatus::Pending);
});

it('consumes a pending invitation when the existing user is added as a member', function () {
    $fixture = createWorkspaceWithRoles();
    $user = User::factory()->create(['email' => 'already@example.com']);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => $user->email,
        'role' => WorkspaceRole::Viewer,
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => $user->email,
        'role' => 'engineer',
    ])->assertCreated();

    expect($user->roleIn($fixture['workspace']))->toBe(WorkspaceRole::Engineer)
        ->and($invitation->fresh()->status)->toBe(WorkspaceInvitationStatus::Accepted);

    $this->getJson('/api/invitations/'.$invitation->token)->assertGone();
});

it('does not add a user who is already a member via invitation', function () {
    $fixture = createWorkspaceWithRoles();

    Sanctum::actingAs($fixture['owner']);

    $this->postJson('/api/workspaces/'.$fixture['workspace']->id.'/invitations', [
        'email' => $fixture['engineer']->email,
        'role' => 'admin',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('rejects invitation acceptance when the email already has an account', function () {
    $fixture = createWorkspaceWithRoles();
    User::factory()->create(['email' => 'taken@example.com']);
    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'invited_by' => $fixture['owner']->id,
        'email' => 'taken@example.com',
        'role' => WorkspaceRole::Engineer,
    ]);

    $this->postJson('/api/invitations/'.$invitation->token.'/accept', [
        'name' => 'Taken Email',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($invitation->fresh()->status)->toBe(WorkspaceInvitationStatus::Pending);
});
