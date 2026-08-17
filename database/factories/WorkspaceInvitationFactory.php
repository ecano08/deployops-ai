<?php

namespace Database\Factories;

use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Engineer,
            'token' => Str::random(64),
            'status' => WorkspaceInvitationStatus::Pending,
            'expires_at' => now()->addDays(WorkspaceInvitation::EXPIRES_AFTER_DAYS),
            'accepted_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkspaceInvitationStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }
}
