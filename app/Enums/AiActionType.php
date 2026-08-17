<?php

namespace App\Enums;

use Illuminate\Validation\ValidationException;

enum AiActionType: string
{
    case UpdateDeploymentStage = 'update_deployment_stage';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validatePayload(array $payload): void
    {
        match ($this) {
            self::UpdateDeploymentStage => $this->validateUpdateDeploymentStagePayload($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateUpdateDeploymentStagePayload(array $payload): void
    {
        $stage = $payload['stage'] ?? null;

        if (! is_string($stage) || DeploymentStage::tryFrom($stage) === null) {
            throw ValidationException::withMessages([
                'payload.stage' => 'A valid deployment stage is required.',
            ]);
        }
    }
}
