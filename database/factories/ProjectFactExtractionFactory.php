<?php

namespace Database\Factories;

use App\Enums\ProjectFactExtractionStatus;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFactExtraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFactExtraction>
 */
class ProjectFactExtractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'knowledge_document_id' => KnowledgeDocument::factory(),
            'source_revision' => 1,
            'status' => ProjectFactExtractionStatus::Pending,
            'proposed_count' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ProjectFactExtraction $extraction): void {
            if ($extraction->deployment_id === null) {
                return;
            }

            $deployment = Deployment::query()->find($extraction->deployment_id);

            if ($deployment !== null) {
                $extraction->workspace_id = $deployment->workspace_id;
                $extraction->customer_id = $deployment->customer_id;
            }
        });
    }

    public function forDocument(KnowledgeDocument $document, ?User $creator = null): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $document->workspace_id,
            'customer_id' => $document->customer_id,
            'deployment_id' => $document->deployment_id,
            'knowledge_document_id' => $document->id,
            'source_revision' => $document->revision_number,
            'created_by' => $creator?->id ?? User::factory(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactExtractionStatus::Pending,
            'proposed_count' => 0,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactExtractionStatus::Processing,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ]);
    }

    public function completed(int $proposedCount = 0): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactExtractionStatus::Completed,
            'proposed_count' => $proposedCount,
            'error_message' => null,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $message = 'Fact extraction failed. Please try again.'): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectFactExtractionStatus::Failed,
            'error_message' => $message,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}
