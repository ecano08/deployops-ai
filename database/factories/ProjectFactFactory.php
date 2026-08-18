<?php

namespace Database\Factories;

use App\Enums\ProjectFactStatus;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFact>
 */
class ProjectFactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'category' => 'framework',
            'key' => 'backend',
            'value' => 'Laravel 13',
            'confidence' => 0.9,
            'status' => ProjectFactStatus::Proposed,
            'source_reference' => 'The backend is built with Laravel 13.',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ProjectFact $fact): void {
            if ($fact->deployment_id === null) {
                return;
            }

            $deployment = Deployment::query()->find($fact->deployment_id);

            if ($deployment !== null) {
                $fact->workspace_id = $deployment->workspace_id;
                $fact->customer_id = $deployment->customer_id;
            }
        });
    }

    public function forDeployment(Deployment $deployment, ?User $creator = null): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'created_by' => $creator?->id ?? User::factory(),
        ]);
    }

    public function fromDocument(KnowledgeDocument $document): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $document->workspace_id,
            'customer_id' => $document->customer_id,
            'deployment_id' => $document->deployment_id,
            'source_document_id' => $document->id,
            'source_revision' => $document->revision_number,
        ]);
    }

    public function proposed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactStatus::Proposed,
            'verified_at' => null,
            'verified_by' => null,
            'superseded_by_id' => null,
        ]);
    }

    public function verified(?User $verifier = null): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $verifier?->id ?? User::factory(),
        ]);
    }

    public function rejected(?User $verifier = null): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactStatus::Rejected,
            'verified_at' => now(),
            'verified_by' => $verifier?->id ?? User::factory(),
        ]);
    }

    public function superseded(?ProjectFact $successor = null): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactStatus::Superseded,
            'superseded_by_id' => $successor?->id,
        ]);
    }
}
