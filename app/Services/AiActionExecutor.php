<?php

namespace App\Services;

use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Enums\DeploymentStage;
use App\Models\AiProposedAction;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AiActionExecutor
{
    public function execute(AiProposedAction $action, User $actor): void
    {
        DB::transaction(function () use ($action, $actor): void {
            $locked = AiProposedAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === AiActionStatus::Executed) {
                return;
            }

            if ($locked->status !== AiActionStatus::Approved) {
                throw new AuthorizationException('This action is not approved for execution.');
            }

            $locked->loadMissing('deployment');

            Gate::forUser($actor)->authorize('execute', $locked);

            $locked->action_type->validatePayload($locked->payload ?? []);
            $this->validateExecutionTarget($locked);

            match ($locked->action_type) {
                AiActionType::UpdateDeploymentStage => $this->executeUpdateDeploymentStage($locked, $actor),
            };

            $updated = AiProposedAction::query()
                ->whereKey($locked->id)
                ->where('status', AiActionStatus::Approved)
                ->update([
                    'status' => AiActionStatus::Executed,
                    'executed_at' => now(),
                    'error_message' => null,
                ]);

            if ($updated !== 1) {
                throw new AuthorizationException('This action has already been executed.');
            }
        });
    }

    private function validateExecutionTarget(AiProposedAction $action): void
    {
        $deployment = $action->deployment;

        if ($deployment === null) {
            throw ValidationException::withMessages([
                'deployment' => 'The deployment for this action no longer exists.',
            ]);
        }

        if ($deployment->id !== $action->deployment_id
            || $deployment->workspace_id !== $action->workspace_id
            || $deployment->customer_id !== $action->customer_id) {
            throw ValidationException::withMessages([
                'deployment' => 'The action target is no longer valid for this deployment scope.',
            ]);
        }
    }

    private function executeUpdateDeploymentStage(AiProposedAction $action, User $actor): void
    {
        $deployment = $action->deployment;

        if (! $deployment instanceof Deployment) {
            throw ValidationException::withMessages([
                'deployment' => 'The deployment for this action no longer exists.',
            ]);
        }

        Gate::forUser($actor)->authorize('changeStage', $deployment);

        $stageValue = $action->payload['stage'] ?? null;

        if (! is_string($stageValue)) {
            throw ValidationException::withMessages([
                'payload.stage' => 'Invalid deployment stage.',
            ]);
        }

        $stage = DeploymentStage::tryFrom($stageValue);

        if ($stage === null) {
            throw ValidationException::withMessages([
                'payload.stage' => 'Invalid deployment stage.',
            ]);
        }

        $deployment->refresh();

        if ($deployment->id !== $action->deployment_id
            || $deployment->workspace_id !== $action->workspace_id
            || $deployment->customer_id !== $action->customer_id) {
            throw ValidationException::withMessages([
                'deployment' => 'The action target is no longer valid for this deployment scope.',
            ]);
        }

        $deployment->update([
            'stage' => $stage,
        ]);
    }
}
