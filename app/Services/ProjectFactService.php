<?php

namespace App\Services;

use App\Enums\ProjectFactStatus;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProjectFactService
{
    /**
     * @param  array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_document_id?: int|null,
     *     source_revision?: int|null,
     *     source_reference?: string|null,
     *     confidence?: float|null,
     *     extraction_metadata?: array<string, mixed>|null
     * }  $attributes
     */
    public function propose(User $creator, Deployment $deployment, array $attributes): ProjectFact
    {
        Gate::forUser($creator)->authorize('create', [ProjectFact::class, $deployment]);

        return DB::transaction(function () use ($creator, $deployment, $attributes): ProjectFact {
            $sourceDocumentId = $attributes['source_document_id'] ?? null;

            if ($sourceDocumentId !== null) {
                $this->assertAuthoritativeSourceDocument($deployment, $sourceDocumentId);
            }

            return ProjectFact::query()->create([
                'workspace_id' => $deployment->workspace_id,
                'customer_id' => $deployment->customer_id,
                'deployment_id' => $deployment->id,
                'category' => $attributes['category'],
                'key' => $attributes['key'],
                'value' => $attributes['value'],
                'source_document_id' => $sourceDocumentId,
                'source_revision' => $attributes['source_revision'] ?? null,
                'source_reference' => $attributes['source_reference'] ?? null,
                'confidence' => $attributes['confidence'] ?? null,
                'status' => ProjectFactStatus::Proposed,
                'created_by' => $creator->id,
                'extraction_metadata' => $attributes['extraction_metadata'] ?? null,
            ]);
        });
    }

    /**
     * @param  array{
     *     category?: string,
     *     key?: string,
     *     value?: string,
     *     source_reference?: string|null,
     *     confidence?: float|null
     * }  $attributes
     */
    public function updateProposed(ProjectFact $fact, User $editor, array $attributes): ProjectFact
    {
        Gate::forUser($editor)->authorize('update', $fact);

        if (! $fact->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Only proposed facts can be edited.',
            ]);
        }

        foreach (['category', 'key', 'value', 'source_reference', 'confidence'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fact->{$field} = $attributes[$field];
            }
        }

        $fact->save();

        return $fact->fresh([
            'sourceDocument:id,title,revision_number,original_filename',
            'creator:id,name,email',
            'verifier:id,name,email',
        ]);
    }

    public function verify(ProjectFact $fact, User $verifier): ProjectFact
    {
        Gate::forUser($verifier)->authorize('verify', $fact);

        return DB::transaction(function () use ($fact, $verifier): ProjectFact {
            $locked = ProjectFact::query()
                ->whereKey($fact->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->markVerified($locked, $verifier);

            return $this->freshFact($locked);
        });
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, ProjectFact>
     */
    public function verifyMany(User $verifier, Deployment $deployment, array $ids): EloquentCollection
    {
        $uniqueIds = $this->uniquePositiveIds($ids);

        return DB::transaction(function () use ($verifier, $deployment, $uniqueIds): EloquentCollection {
            $facts = $this->lockFactsForDeployment($deployment, $uniqueIds);

            foreach ($facts as $fact) {
                Gate::forUser($verifier)->authorize('verify', $fact);
            }

            foreach ($facts as $fact) {
                $this->markVerified($fact, $verifier);
            }

            return $this->freshFacts($uniqueIds);
        });
    }

    public function reject(ProjectFact $fact, User $reviewer): ProjectFact
    {
        Gate::forUser($reviewer)->authorize('reject', $fact);

        return DB::transaction(function () use ($fact, $reviewer): ProjectFact {
            $locked = ProjectFact::query()
                ->whereKey($fact->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->markRejected($locked, $reviewer);

            return $this->freshFact($locked);
        });
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, ProjectFact>
     */
    public function rejectMany(User $reviewer, Deployment $deployment, array $ids): EloquentCollection
    {
        $uniqueIds = $this->uniquePositiveIds($ids);

        return DB::transaction(function () use ($reviewer, $deployment, $uniqueIds): EloquentCollection {
            $facts = $this->lockFactsForDeployment($deployment, $uniqueIds);

            foreach ($facts as $fact) {
                Gate::forUser($reviewer)->authorize('reject', $fact);
            }

            foreach ($facts as $fact) {
                $this->markRejected($fact, $reviewer);
            }

            return $this->freshFacts($uniqueIds);
        });
    }

    /**
     * @return EloquentCollection<int, ProjectFact>
     */
    public function rejectProposedFromSource(
        User $reviewer,
        Deployment $deployment,
        int $sourceDocumentId,
        int $sourceRevision,
    ): EloquentCollection {
        return DB::transaction(function () use ($reviewer, $deployment, $sourceDocumentId, $sourceRevision): EloquentCollection {
            $facts = ProjectFact::query()
                ->forDeployment($deployment)
                ->where('source_document_id', $sourceDocumentId)
                ->where('source_revision', $sourceRevision)
                ->where('status', ProjectFactStatus::Proposed)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($facts->isEmpty()) {
                throw ValidationException::withMessages([
                    'source_document_id' => 'No proposed facts were found for this document revision.',
                ]);
            }

            foreach ($facts as $fact) {
                Gate::forUser($reviewer)->authorize('reject', $fact);
            }

            foreach ($facts as $fact) {
                $this->markRejected($fact, $reviewer);
            }

            return $this->freshFacts($facts->modelKeys());
        });
    }

    /**
     * @return array{proposed_count: int, verified_count: int, rejected_count: int}
     */
    public function statsForDeployment(Deployment $deployment): array
    {
        return [
            'proposed_count' => ProjectFact::query()
                ->forDeployment($deployment)
                ->where('status', ProjectFactStatus::Proposed)
                ->count(),
            'verified_count' => ProjectFact::query()
                ->forDeployment($deployment)
                ->where('status', ProjectFactStatus::Verified)
                ->count(),
            'rejected_count' => ProjectFact::query()
                ->forDeployment($deployment)
                ->where('status', ProjectFactStatus::Rejected)
                ->count(),
        ];
    }

    public function assertAuthoritativeSourceDocument(Deployment $deployment, int $documentId): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()
            ->where('deployment_id', $deployment->id)
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            throw ValidationException::withMessages([
                'source_document_id' => 'The selected source document was not found in this deployment.',
            ]);
        }

        if (! $document->isAuthoritativeForRag()) {
            throw ValidationException::withMessages([
                'source_document_id' => 'Facts can only be sourced from active, ready project documentation.',
            ]);
        }

        return $document;
    }

    /**
     * @param  list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null
     * }>  $extractedFacts
     * @return list<ProjectFact>
     */
    public function proposeExtractedFacts(
        User $creator,
        KnowledgeDocument $document,
        array $extractedFacts,
    ): array {
        if (! $document->isAuthoritativeForRag()) {
            throw new InvalidArgumentException('Facts can only be extracted from active, ready project documentation.');
        }

        if ($document->deployment_id === null) {
            throw new InvalidArgumentException('Source document is missing deployment scope.');
        }

        $deployment = $document->deployment;

        if ($deployment === null) {
            throw new InvalidArgumentException('Source document deployment could not be resolved.');
        }

        Gate::forUser($creator)->authorize('extract', [ProjectFact::class, $deployment]);

        return DB::transaction(function () use ($creator, $deployment, $document, $extractedFacts): array {
            $existingFingerprints = ProjectFact::query()
                ->forDeployment($deployment)
                ->where('source_document_id', $document->id)
                ->where('source_revision', $document->revision_number)
                ->whereIn('status', [ProjectFactStatus::Proposed, ProjectFactStatus::Verified])
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn (ProjectFact $fact): array => [
                    ProjectFact::equivalentFingerprint($fact->category, $fact->key, $fact->value) => true,
                ])
                ->all();

            $created = [];

            foreach ($extractedFacts as $extracted) {
                $fingerprint = ProjectFact::equivalentFingerprint(
                    $extracted['category'],
                    $extracted['key'],
                    $extracted['value'],
                );

                if (isset($existingFingerprints[$fingerprint])) {
                    continue;
                }

                $created[] = $this->propose($creator, $deployment, [
                    'category' => $extracted['category'],
                    'key' => $extracted['key'],
                    'value' => $extracted['value'],
                    'source_document_id' => $document->id,
                    'source_revision' => $document->revision_number,
                    'source_reference' => $extracted['source_reference'],
                    'confidence' => $extracted['confidence'] ?? null,
                    'extraction_metadata' => array_filter([
                        'extracted_at' => now()->toIso8601String(),
                        'model' => config('services.openai.model'),
                        'source_chunk_index' => $extracted['source_chunk_index'] ?? null,
                        'content_source' => $extracted['content_source'] ?? null,
                    ], fn (mixed $value): bool => $value !== null),
                ]);

                $existingFingerprints[$fingerprint] = true;
            }

            return $created;
        });
    }

    /**
     * @return list<string>
     */
    private function factRelations(): array
    {
        return [
            'sourceDocument:id,title,revision_number,original_filename',
            'creator:id,name,email',
            'verifier:id,name,email',
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function uniquePositiveIds(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, ProjectFact>
     */
    private function lockFactsForDeployment(Deployment $deployment, array $ids): EloquentCollection
    {
        $facts = ProjectFact::query()
            ->forDeployment($deployment)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($facts->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => 'One or more facts were not found in this deployment.',
            ]);
        }

        return $facts;
    }

    private function markVerified(ProjectFact $fact, User $verifier): void
    {
        if ($fact->status !== ProjectFactStatus::Proposed) {
            throw ValidationException::withMessages([
                'status' => 'Only proposed facts can be verified.',
            ]);
        }

        $existingVerified = ProjectFact::query()
            ->where('workspace_id', $fact->workspace_id)
            ->where('customer_id', $fact->customer_id)
            ->where('deployment_id', $fact->deployment_id)
            ->where('category', $fact->category)
            ->where('key', $fact->key)
            ->where('status', ProjectFactStatus::Verified)
            ->lockForUpdate()
            ->get();

        $fact->update([
            'status' => ProjectFactStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $verifier->id,
        ]);

        foreach ($existingVerified as $previous) {
            $previous->update([
                'status' => ProjectFactStatus::Superseded,
                'superseded_by_id' => $fact->id,
            ]);
        }
    }

    private function markRejected(ProjectFact $fact, User $reviewer): void
    {
        if ($fact->status !== ProjectFactStatus::Proposed) {
            throw ValidationException::withMessages([
                'status' => 'Only proposed facts can be rejected.',
            ]);
        }

        $fact->update([
            'status' => ProjectFactStatus::Rejected,
            'verified_at' => now(),
            'verified_by' => $reviewer->id,
        ]);
    }

    private function freshFact(ProjectFact $fact): ProjectFact
    {
        return $fact->fresh($this->factRelations()) ?? $fact;
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, ProjectFact>
     */
    private function freshFacts(array $ids): EloquentCollection
    {
        return ProjectFact::query()
            ->whereIn('id', $ids)
            ->with($this->factRelations())
            ->orderBy('id')
            ->get();
    }
}
