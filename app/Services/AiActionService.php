<?php

namespace App\Services;

use App\Enums\AiActionAuditEventType;
use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use App\Models\AiProposedAction;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AiActionService
{
    public function __construct(private AiActionExecutor $executor) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function propose(
        User $requester,
        Deployment $deployment,
        AiActionType $actionType,
        array $payload,
    ): AiProposedAction {
        Gate::forUser($requester)->authorize('propose', [AiProposedAction::class, $deployment]);

        $actionType->validatePayload($payload);

        $normalizedPayload = $actionType->normalizePayload($payload);

        return DB::transaction(function () use ($requester, $deployment, $actionType, $normalizedPayload): AiProposedAction {
            Deployment::query()
                ->whereKey($deployment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->findEquivalentPendingAction(
                deployment: $deployment,
                actionType: $actionType,
                normalizedPayload: $normalizedPayload,
            );

            if ($existing !== null) {
                return $existing;
            }

            $action = AiProposedAction::query()->create([
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'deployment_id' => $deployment->id,
                'action_type' => $actionType,
                'payload' => $normalizedPayload,
                'status' => AiActionStatus::Pending,
                'requested_by' => $requester->id,
            ]);

            $this->recordAuditEvent($action, AiActionAuditEventType::Proposed, $requester, [
                'action_type' => $actionType->value,
                'payload' => $normalizedPayload,
            ]);

            return $action;
        });
    }

    public function approve(AiProposedAction $action, User $approver): AiProposedAction
    {
        Gate::forUser($approver)->authorize('approve', $action);

        return DB::transaction(function () use ($action, $approver): AiProposedAction {
            $locked = AiProposedAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === AiActionStatus::Executed) {
                throw ValidationException::withMessages([
                    'status' => 'This action has already been executed.',
                ]);
            }

            if ($locked->status !== AiActionStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending actions can be approved.',
                ]);
            }

            $locked->update([
                'status' => AiActionStatus::Approved,
                'approved_by' => $approver->id,
            ]);

            $this->recordAuditEvent($locked, AiActionAuditEventType::Approved, $approver);

            try {
                $this->executor->execute($locked, $approver);

                $this->recordAuditEvent($locked, AiActionAuditEventType::Executed, $approver);
            } catch (\Throwable $exception) {
                $locked->update([
                    'status' => AiActionStatus::Failed,
                    'error_message' => $this->safeErrorMessage($exception),
                ]);

                $this->recordAuditEvent($locked, AiActionAuditEventType::Failed, $approver, [
                    'message' => $locked->error_message,
                ]);
            }

            return $locked->fresh(['auditEvents', 'requester', 'approver', 'workspace.members']);
        });
    }

    public function reject(AiProposedAction $action, User $approver): AiProposedAction
    {
        Gate::forUser($approver)->authorize('reject', $action);

        return DB::transaction(function () use ($action, $approver): AiProposedAction {
            $locked = AiProposedAction::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== AiActionStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending actions can be rejected.',
                ]);
            }

            $locked->update([
                'status' => AiActionStatus::Rejected,
                'approved_by' => $approver->id,
            ]);

            $this->recordAuditEvent($locked, AiActionAuditEventType::Rejected, $approver);

            return $locked->fresh(['auditEvents', 'requester', 'approver', 'workspace.members']);
        });
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    private function findEquivalentPendingAction(
        Deployment $deployment,
        AiActionType $actionType,
        array $normalizedPayload,
    ): ?AiProposedAction {
        $pendingActions = AiProposedAction::query()
            ->where('workspace_id', $deployment->workspace_id)
            ->where('customer_id', $deployment->customer_id)
            ->where('deployment_id', $deployment->id)
            ->where('action_type', $actionType)
            ->where('status', AiActionStatus::Pending)
            ->lockForUpdate()
            ->get();

        foreach ($pendingActions as $pendingAction) {
            if ($actionType->normalizePayload($pendingAction->payload ?? []) === $normalizedPayload) {
                return $pendingAction;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordAuditEvent(
        AiProposedAction $action,
        AiActionAuditEventType $eventType,
        ?User $performer,
        ?array $metadata = null,
    ): void {
        $action->auditEvents()->create([
            'event_type' => $eventType,
            'performed_by' => $performer?->id,
            'metadata' => $metadata,
        ]);
    }

    private function safeErrorMessage(\Throwable $exception): string
    {
        if ($exception instanceof AuthorizationException) {
            return 'Action execution was not authorized.';
        }

        if ($exception instanceof ValidationException) {
            return 'Action payload was invalid.';
        }

        return 'Action execution failed.';
    }
}
