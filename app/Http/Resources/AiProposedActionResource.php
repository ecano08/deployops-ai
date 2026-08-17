<?php

namespace App\Http\Resources;

use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiProposedActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actionType = $this->action_type;
        $status = $this->status;

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'deployment_id' => $this->deployment_id,
            'action_type' => $actionType instanceof AiActionType ? $actionType->value : (string) $actionType,
            'payload' => $this->sanitizedPayload(),
            'status' => $status instanceof AiActionStatus ? $status->value : (string) $status,
            'requested_by' => AiActionRequesterResource::make(
                $this->relationLoaded('requester') ? $this->requester : null,
                $this->requested_by,
                $this->relationLoaded('workspace') ? $this->workspace : null,
            ),
            'approved_by' => $this->approved_by,
            'executed_at' => $this->executed_at,
            'error_message' => $this->error_message,
            'audit_events' => AiActionAuditEventResource::collection($this->whenLoaded('auditEvents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizedPayload(): array
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return match ($this->action_type) {
            AiActionType::UpdateDeploymentStage => array_filter([
                'stage' => $payload['stage'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            default => [],
        };
    }
}
