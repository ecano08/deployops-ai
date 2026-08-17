<?php

use App\Enums\AiActionAuditEventType;
use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Enums\DeploymentStage;
use App\Models\AiProposedAction;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Services\AiActionExecutor;
use App\Services\AiActionService;
use App\Services\CopilotContext;
use App\Services\CopilotToolExecutor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'services.openai.api_key' => 'test-openai-key',
        'services.openai.model' => 'gpt-4.1-mini',
    ]);
});

it('requires authentication for ai actions', function () {
    $workspace = Workspace::factory()->create();
    $customer = Customer::factory()->forWorkspace($workspace)->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $this->getJson(aiActionsPath($workspace, $customer, $deployment))
        ->assertUnauthorized();
});

it('allows engineers to propose ai actions without executing them', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(aiActionsPath($fixture['workspace'], $customer, $deployment), [
        'action_type' => AiActionType::UpdateDeploymentStage->value,
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', AiActionStatus::Pending->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Discovery);

    $this->assertDatabaseHas('ai_action_audit_events', [
        'event_type' => AiActionAuditEventType::Proposed->value,
    ]);
});

it('forbids viewers from proposing ai actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    Sanctum::actingAs($fixture['viewer']);

    $this->postJson(aiActionsPath($fixture['workspace'], $customer, $deployment), [
        'action_type' => AiActionType::UpdateDeploymentStage->value,
        'payload' => ['stage' => DeploymentStage::Build->value],
    ])->assertForbidden();
});

it('forbids engineers from approving ai actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )->assertForbidden();
});

it('allows admins to approve and execute deployment stage changes', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => ['stage' => DeploymentStage::Validation->value],
    ]);

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )
        ->assertOk()
        ->assertJsonPath('data.status', AiActionStatus::Executed->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Validation);

    $this->assertDatabaseHas('ai_action_audit_events', [
        'ai_proposed_action_id' => $action->id,
        'event_type' => AiActionAuditEventType::Executed->value,
    ]);
});

it('does not execute ai actions without approval', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => ['stage' => DeploymentStage::Deployed->value],
    ]);

    expect(fn () => app(AiActionExecutor::class)->execute($action, $fixture['admin']))
        ->toThrow(AuthorizationException::class);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Discovery);
});

it('re-checks authorization before executing approved actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'status' => AiActionStatus::Approved,
        'approved_by' => $fixture['admin']->id,
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    Sanctum::actingAs($fixture['viewer']);

    expect(fn () => app(AiActionExecutor::class)->execute($action, $fixture['viewer']))
        ->toThrow(AuthorizationException::class);
});

it('isolates ai actions by deployment tenant scope', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $otherDeployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $otherDeployment).'/'.$action->id.'/approve',
    )->assertNotFound();
});

it('executes the propose deployment stage tool directly', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();

    $context = new CopilotContext(
        user: $fixture['engineer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );

    $result = app(CopilotToolExecutor::class)->validateAndExecute(
        $context,
        'propose_update_deployment_stage',
        ['stage' => DeploymentStage::Build->value],
    );

    expect($result)->toHaveKey('action_id')
        ->and(AiProposedAction::query()->count())->toBe(1);
});

it('creates pending actions from the copilot propose tool without mutating data', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Integration)->create();

    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(openAiToolCallResponse('propose_update_deployment_stage', json_encode([
                'stage' => DeploymentStage::Build->value,
            ])))
            ->push(openAiMessageResponse('I proposed moving the deployment to build for approval.')),
    ]);

    Sanctum::actingAs($fixture['engineer']);

    $response = $this->postJson(copilotPath($fixture['workspace'], $customer, $deployment), [
        'message' => 'Move this deployment to build.',
    ]);

    $response->assertOk();

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Integration)
        ->and(AiProposedAction::query()->count())->toBe(1)
        ->and(AiProposedAction::query()->value('status'))->toBe(AiActionStatus::Pending);
});

it('records rejection audit events without executing actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => ['stage' => DeploymentStage::Deployed->value],
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/reject',
    )
        ->assertOk()
        ->assertJsonPath('data.status', AiActionStatus::Rejected->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Discovery);

    $this->assertDatabaseHas('ai_action_audit_events', [
        'ai_proposed_action_id' => $action->id,
        'event_type' => AiActionAuditEventType::Rejected->value,
    ]);
});

it('lists pending ai actions for approval', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();
    AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'status' => AiActionStatus::Rejected,
    ]);

    Sanctum::actingAs($fixture['admin']);

    $this->getJson(aiActionsPath($fixture['workspace'], $customer, $deployment).'/pending')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('never exposes secrets in ai action responses', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => [
            'stage' => DeploymentStage::Build->value,
            'api_key' => 'super-secret-key',
        ],
    ]);

    Sanctum::actingAs($fixture['admin']);

    $response = $this->getJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id,
    );

    $response->assertOk();

    expect($response->getContent())->not->toContain('super-secret-key');
});

it('prevents mutating proposed action type and payload after creation', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    expect(fn () => $action->update(['payload' => ['stage' => DeploymentStage::Deployed->value]]))
        ->toThrow(ValidationException::class);

    expect(fn () => $action->update(['action_type' => AiActionType::UpdateDeploymentStage]))
        ->toThrow(ValidationException::class);
});

it('rejects approving an action that has already executed', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )->assertOk();

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )->assertUnprocessable();

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Build)
        ->and($action->fresh()->status)->toBe(AiActionStatus::Executed);
});

it('does not execute an already executed action twice', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Build)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'status' => AiActionStatus::Executed,
        'approved_by' => $fixture['admin']->id,
        'executed_at' => now(),
        'payload' => ['stage' => DeploymentStage::Deployed->value],
    ]);

    app(AiActionExecutor::class)->execute($action, $fixture['admin']);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Build);
});

it('reuses an equivalent pending action instead of creating duplicates', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $service = app(AiActionService::class);

    $first = $service->propose(
        requester: $fixture['engineer'],
        deployment: $deployment,
        actionType: AiActionType::UpdateDeploymentStage,
        payload: ['stage' => DeploymentStage::Validation->value],
    );

    $second = $service->propose(
        requester: $fixture['engineer'],
        deployment: $deployment,
        actionType: AiActionType::UpdateDeploymentStage,
        payload: ['stage' => DeploymentStage::Validation->value],
    );

    expect($second->id)->toBe($first->id)
        ->and(AiProposedAction::query()->where('status', AiActionStatus::Pending)->count())->toBe(1)
        ->and($first->auditEvents()->where('event_type', AiActionAuditEventType::Proposed)->count())->toBe(1);
});

it('creates separate pending actions for different target stages', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $service = app(AiActionService::class);

    $validationAction = $service->propose(
        requester: $fixture['engineer'],
        deployment: $deployment,
        actionType: AiActionType::UpdateDeploymentStage,
        payload: ['stage' => DeploymentStage::Validation->value],
    );

    $buildAction = $service->propose(
        requester: $fixture['engineer'],
        deployment: $deployment,
        actionType: AiActionType::UpdateDeploymentStage,
        payload: ['stage' => DeploymentStage::Build->value],
    );

    expect($buildAction->id)->not->toBe($validationAction->id)
        ->and(AiProposedAction::query()->where('status', AiActionStatus::Pending)->count())->toBe(2);
});

it('allows proposing again after a previous equivalent action was rejected', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $service = app(AiActionService::class);

    $rejected = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'status' => AiActionStatus::Rejected,
        'approved_by' => $fixture['admin']->id,
        'payload' => ['stage' => DeploymentStage::Validation->value],
    ]);

    $newAction = $service->propose(
        requester: $fixture['engineer'],
        deployment: $deployment,
        actionType: AiActionType::UpdateDeploymentStage,
        payload: ['stage' => DeploymentStage::Validation->value],
    );

    expect($newAction->id)->not->toBe($rejected->id)
        ->and($newAction->status)->toBe(AiActionStatus::Pending)
        ->and(AiProposedAction::query()->where('status', AiActionStatus::Pending)->count())->toBe(1);
});

it('allows proposing again after a previous equivalent action was executed', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Validation)->create();
    $service = app(AiActionService::class);

    $executed = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'status' => AiActionStatus::Executed,
        'approved_by' => $fixture['admin']->id,
        'executed_at' => now(),
        'payload' => ['stage' => DeploymentStage::Validation->value],
    ]);

    $newAction = $service->propose(
        requester: $fixture['engineer'],
        deployment: $deployment,
        actionType: AiActionType::UpdateDeploymentStage,
        payload: ['stage' => DeploymentStage::Validation->value],
    );

    expect($newAction->id)->not->toBe($executed->id)
        ->and($newAction->status)->toBe(AiActionStatus::Pending);
});

it('returns the existing pending action through the api without creating duplicates', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();

    Sanctum::actingAs($fixture['engineer']);

    $firstResponse = $this->postJson(aiActionsPath($fixture['workspace'], $customer, $deployment), [
        'action_type' => AiActionType::UpdateDeploymentStage->value,
        'payload' => ['stage' => DeploymentStage::Validation->value],
    ]);

    $firstResponse->assertCreated();

    $secondResponse = $this->postJson(aiActionsPath($fixture['workspace'], $customer, $deployment), [
        'action_type' => AiActionType::UpdateDeploymentStage->value,
        'payload' => ['stage' => DeploymentStage::Validation->value],
    ]);

    $secondResponse
        ->assertOk()
        ->assertJsonPath('data.id', $firstResponse->json('data.id'));

    expect(AiProposedAction::query()->where('status', AiActionStatus::Pending)->count())->toBe(1);
});

it('reuses equivalent pending actions from the copilot propose tool', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $context = new CopilotContext(
        user: $fixture['engineer'],
        workspace: $fixture['workspace'],
        customer: $customer,
        deployment: $deployment,
    );
    $executor = app(CopilotToolExecutor::class);

    $first = $executor->validateAndExecute(
        $context,
        'propose_update_deployment_stage',
        ['stage' => DeploymentStage::Validation->value],
    );

    $second = $executor->validateAndExecute(
        $context,
        'propose_update_deployment_stage',
        ['stage' => DeploymentStage::Validation->value],
    );

    expect($second['action_id'])->toBe($first['action_id'])
        ->and(AiProposedAction::query()->where('status', AiActionStatus::Pending)->count())->toBe(1);
});

it('serializes concurrent equivalent proposals to a single pending action', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $service = app(AiActionService::class);

    DB::transaction(function () use ($service, $fixture, $deployment): void {
        $first = $service->propose(
            requester: $fixture['engineer'],
            deployment: $deployment,
            actionType: AiActionType::UpdateDeploymentStage,
            payload: ['stage' => DeploymentStage::Validation->value],
        );

        $second = $service->propose(
            requester: $fixture['engineer'],
            deployment: $deployment,
            actionType: AiActionType::UpdateDeploymentStage,
            payload: ['stage' => DeploymentStage::Validation->value],
        );

        expect($second->id)->toBe($first->id)
            ->and(AiProposedAction::query()->where('status', AiActionStatus::Pending)->count())->toBe(1);
    });
});

it('forbids requesters from approving their own ai actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['admin'])->create();

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )
        ->assertForbidden()
        ->assertJsonPath('message', 'You cannot approve an action you requested yourself.');
});

it('allows requesters to reject their own pending ai actions when authorized', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['owner'])->create([
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    Sanctum::actingAs($fixture['owner']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/reject',
    )
        ->assertOk()
        ->assertJsonPath('data.status', AiActionStatus::Rejected->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Discovery)
        ->and($action->fresh()->status)->toBe(AiActionStatus::Rejected);
});

it('allows admins to reject their own pending ai actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Integration)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['admin'])->create([
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/reject',
    )
        ->assertOk()
        ->assertJsonPath('data.status', AiActionStatus::Rejected->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Integration);
});

it('forbids engineers from rejecting their own pending ai actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();

    Sanctum::actingAs($fixture['engineer']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/reject',
    )->assertForbidden();
});

it('forbids unrelated workspace users from approving or rejecting ai actions', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();

    Sanctum::actingAs($fixture['stranger']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )->assertForbidden();

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/reject',
    )->assertForbidden();
});

it('allows another authorized admin to approve a requesters pending ai action', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['owner'])->create([
        'payload' => ['stage' => DeploymentStage::Validation->value],
    ]);

    Sanctum::actingAs($fixture['admin']);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )
        ->assertOk()
        ->assertJsonPath('data.status', AiActionStatus::Executed->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Validation);
});

it('serializes workspace requester identity in ai action responses', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();

    Sanctum::actingAs($fixture['admin']);

    $this->getJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id,
    )
        ->assertOk()
        ->assertJsonPath('data.requested_by.id', $fixture['engineer']->id)
        ->assertJsonPath('data.requested_by.name', $fixture['engineer']->name)
        ->assertJsonPath('data.requested_by.email', $fixture['engineer']->email);
});

it('does not expose requester details outside the action workspace', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create();

    DB::table('ai_proposed_actions')
        ->where('id', $action->id)
        ->update(['requested_by' => $fixture['stranger']->id]);

    Sanctum::actingAs($fixture['admin']);

    $response = $this->getJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id,
    );

    $response
        ->assertOk()
        ->assertJsonPath('data.requested_by.id', $fixture['stranger']->id)
        ->assertJsonPath('data.requested_by.name', null)
        ->assertJsonMissingPath('data.requested_by.email');
});

it('fails execution when a tampered payload is invalid at execution time', function () {
    $fixture = createWorkspaceWithRoles();
    $customer = Customer::factory()->forWorkspace($fixture['workspace'])->create();
    $deployment = Deployment::factory()->forCustomer($customer)->stage(DeploymentStage::Discovery)->create();
    $action = AiProposedAction::factory()->forDeployment($deployment, $fixture['engineer'])->create([
        'payload' => ['stage' => DeploymentStage::Build->value],
    ]);

    Sanctum::actingAs($fixture['admin']);

    DB::table('ai_proposed_actions')
        ->where('id', $action->id)
        ->update(['payload' => json_encode(['stage' => 'not-a-real-stage'])]);

    $this->postJson(
        aiActionsPath($fixture['workspace'], $customer, $deployment).'/'.$action->id.'/approve',
    )
        ->assertOk()
        ->assertJsonPath('data.status', AiActionStatus::Failed->value);

    $deployment->refresh();

    expect($deployment->stage)->toBe(DeploymentStage::Discovery);
});
