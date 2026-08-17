<?php

namespace Database\Factories;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Models\Customer;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->word().'.txt';

        return [
            'deployment_id' => Deployment::factory(),
            'uploaded_by' => User::factory(),
            'title' => $filename,
            'document_type' => KnowledgeDocumentType::Other,
            'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Draft,
            'original_filename' => $filename,
            'mime_type' => 'text/plain',
            'disk_path' => 'knowledge/1/1/1/sample.txt',
            'size_bytes' => fake()->numberBetween(100, 5000),
            'status' => KnowledgeDocumentStatus::Pending,
            'chunk_count' => 0,
            'revision_number' => 1,
            'content_hash' => hash('sha256', fake()->uuid()),
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (KnowledgeDocument $document): void {
                if ($document->deployment_id === null) {
                    return;
                }

                $deployment = Deployment::query()->find($document->deployment_id);

                if ($deployment !== null) {
                    $document->workspace_id = $deployment->workspace_id;
                    $document->customer_id = $deployment->customer_id;
                }
            })
            ->afterCreating(function (KnowledgeDocument $document): void {
                if ($document->chain_root_id !== null) {
                    return;
                }

                if ($document->supersedes_document_id !== null) {
                    $chainRootId = KnowledgeDocument::query()
                        ->whereKey($document->supersedes_document_id)
                        ->value('chain_root_id');

                    if ($chainRootId !== null) {
                        $document->update(['chain_root_id' => $chainRootId]);

                        return;
                    }
                }

                $document->update(['chain_root_id' => $document->id]);
            });
    }

    public function forDeployment(Deployment $deployment, ?User $uploader = null): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'uploaded_by' => $uploader?->id ?? User::factory(),
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $customer->workspace_id,
            'customer_id' => $customer->id,
        ]);
    }

    public function ready(int $chunkCount = 3): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeDocumentStatus::Ready,
            'chunk_count' => $chunkCount,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Active,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'lifecycle_status' => KnowledgeDocumentLifecycleStatus::Draft,
        ]);
    }

    public function failed(string $message = 'Processing failed.'): static
    {
        return $this->state(fn (): array => [
            'status' => KnowledgeDocumentStatus::Failed,
            'error_message' => $message,
        ]);
    }
}
