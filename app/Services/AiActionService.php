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

        return DB::transaction(function () use ($requester, $deployment, $actionType, $payload): AiProposedAction {
            $action = AiProposedAction::query()->create([
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'deployment_id' => $deployment->id,
                'action_type' => $actionType,
                'payload' => $payload,
                'status' => AiActionStatus::Pending,
                'requested_by' => $requester->id,
            ]);

            $this->recordAuditEvent($action, AiActionAuditEventType::Proposed, $requester, [
                'action_type' => $actionType->value,
                'payload' => $payload,
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

            return $locked->fresh(['auditEvents', 'requester', 'approver']);
        });
    }

    public function reject(AiProposedAction $action, User $approver): AiProposedAction
    {
        Gate::forUser($approver)->authorize('approve', $action);

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

            return $locked->fresh(['auditEvents', 'requester', 'approver']);
        });
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
