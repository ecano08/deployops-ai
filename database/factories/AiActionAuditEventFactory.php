<?php

namespace Database\Factories;

use App\Enums\AiActionAuditEventType;
use App\Models\AiActionAuditEvent;
use App\Models\AiProposedAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiActionAuditEvent>
 */
class AiActionAuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type' => AiActionAuditEventType::Proposed,
            'metadata' => null,
        ];
    }

    public function forAction(AiProposedAction $action, ?User $performer = null): static
    {
        return $this->state(fn (): array => [
            'ai_proposed_action_id' => $action->id,
            'performed_by' => $performer?->id,
        ]);
    }
}
