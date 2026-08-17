<?php

namespace App\Services;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KnowledgeDocumentLibraryService
{
    /**
     * @return array{
     *     revision_total: int,
     *     ready_count: int,
     *     active_count: int,
     *     needs_attention_count: int,
     * }
     */
    public function deploymentStats(Deployment $deployment): array
    {
        $baseQuery = KnowledgeDocument::query()->where('deployment_id', $deployment->id);

        return [
            'revision_total' => (clone $baseQuery)->count(),
            'ready_count' => (clone $baseQuery)
                ->where('status', KnowledgeDocumentStatus::Ready)
                ->count(),
            'active_count' => (clone $baseQuery)
                ->where('lifecycle_status', KnowledgeDocumentLifecycleStatus::Active)
                ->where('status', KnowledgeDocumentStatus::Ready)
                ->count(),
            'needs_attention_count' => $this->countNeedsAttentionChains($deployment),
        ];
    }

    /**
     * @param  array{
     *     view?: string|null,
     *     search?: string|null,
     *     document_type?: string|null,
     *     lifecycle_status?: string|null,
     *     attention?: string|null,
     *     status?: string|null,
     *     sort?: string|null,
     *     direction?: string|null,
     *     page?: int|null,
     *     per_page?: int|null,
     * }  $filters
     */
    public function paginateLibrary(Deployment $deployment, array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $sort = $filters['sort'] ?? 'updated_at';
        $direction = $filters['direction'] ?? 'desc';

        $chainRootIds = $this->filteredChainRootIds($deployment, $filters);

        if ($chainRootIds->isEmpty()) {
            return new \Illuminate\Pagination\LengthAwarePaginator(
                collect(),
                0,
                $perPage,
                $page,
                ['path' => request()->url(), 'query' => request()->query()],
            );
        }

        $summaries = $this->buildChainSummaries($deployment, $chainRootIds);
        $summaries = $this->sortSummaries($summaries, $sort, $direction);

        $total = $summaries->count();
        $pageItems = $summaries
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        $documentIds = $pageItems
            ->flatMap(fn (array $summary): array => array_filter([
                $summary['active_id'],
                $summary['head_id'],
                $summary['attention_draft_id'],
            ]))
            ->unique()
            ->values()
            ->all();

        $documents = KnowledgeDocument::query()
            ->whereIn('id', $documentIds)
            ->get()
            ->keyBy('id');

        $entries = $pageItems->map(function (array $summary) use ($documents): array {
            $active = $summary['active_id'] !== null
                ? $documents->get($summary['active_id'])
                : null;
            $head = $documents->get($summary['head_id']);
            $attentionDraft = $summary['attention_draft_id'] !== null
                ? $documents->get($summary['attention_draft_id'])
                : null;

            return [
                'chain_root_id' => $summary['chain_root_id'],
                'title' => $active?->title ?? $head?->title ?? 'Untitled document',
                'document_type' => $active?->document_type ?? $head?->document_type,
                'revision_count' => $summary['revision_count'],
                'needs_attention' => $summary['needs_attention'],
                'attention_reason' => $summary['attention_reason'],
                'updated_at' => $summary['updated_at'],
                'effective_at' => $active?->effective_at ?? $head?->effective_at,
                'active_revision' => $active,
                'chain_head' => $head,
                'attention_draft' => $attentionDraft,
                'view_document_id' => $active?->id ?? $head?->id,
            ];
        });

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $entries,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  array{
     *     view?: string|null,
     *     search?: string|null,
     *     document_type?: string|null,
     *     lifecycle_status?: string|null,
     *     attention?: string|null,
     *     status?: string|null,
     * }  $filters
     * @return Collection<int, int>
     */
    private function filteredChainRootIds(Deployment $deployment, array $filters): Collection
    {
        $summaries = $this->buildChainSummaries(
            $deployment,
            $this->baseChainRootIds($deployment, $filters),
        );

        $view = $filters['view'] ?? 'current';

        $summaries = $summaries->filter(function (array $summary) use ($view): bool {
            return match ($view) {
                'needs_attention' => $summary['needs_attention'],
                'archived' => $summary['is_archived_chain'],
                default => ! $summary['is_archived_chain'],
            };
        });

        if (($filters['attention'] ?? null) === 'needs_attention') {
            $summaries = $summaries->filter(fn (array $summary): bool => $summary['needs_attention']);
        }

        if (($filters['attention'] ?? null) === 'processing_failed') {
            $summaries = $summaries->filter(fn (array $summary): bool => $summary['has_processing_failure']);
        }

        if (($filters['attention'] ?? null) === 'draft_pending') {
            $summaries = $summaries->filter(fn (array $summary): bool => $summary['has_pending_draft']);
        }

        if (($filters['lifecycle_status'] ?? null) !== null) {
            $lifecycleStatus = KnowledgeDocumentLifecycleStatus::from($filters['lifecycle_status']);

            $summaries = $summaries->filter(function (array $summary) use ($lifecycleStatus): bool {
                return $summary['representative_lifecycle_status'] === $lifecycleStatus;
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $status = KnowledgeDocumentStatus::from($filters['status']);

            $summaries = $summaries->filter(function (array $summary) use ($status): bool {
                return $summary['representative_status'] === $status;
            });
        }

        if (($filters['document_type'] ?? null) !== null) {
            $documentType = KnowledgeDocumentType::from($filters['document_type']);

            $summaries = $summaries->filter(function (array $summary) use ($documentType): bool {
                return $summary['document_type'] === $documentType;
            });
        }

        return $summaries->pluck('chain_root_id');
    }

    /**
     * @param  array{
     *     search?: string|null,
     * }  $filters
     * @return Collection<int, int>
     */
    private function baseChainRootIds(Deployment $deployment, array $filters): Collection
    {
        $query = KnowledgeDocument::query()
            ->where('deployment_id', $deployment->id)
            ->whereNotNull('chain_root_id')
            ->select('chain_root_id')
            ->distinct();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);

            $query->where(function (Builder $builder) use ($escaped): void {
                $builder
                    ->where('title', 'like', '%'.$escaped.'%')
                    ->orWhere('original_filename', 'like', '%'.$escaped.'%');
            });
        }

        return $query->pluck('chain_root_id');
    }

    /**
     * @param  Collection<int, int>  $chainRootIds
     * @return Collection<int, array{
     *     chain_root_id: int,
     *     revision_count: int,
     *     active_id: int|null,
     *     head_id: int,
     *     attention_draft_id: int|null,
     *     needs_attention: bool,
     *     attention_reason: string|null,
     *     is_archived_chain: bool,
     *     has_processing_failure: bool,
     *     has_pending_draft: bool,
     *     representative_lifecycle_status: KnowledgeDocumentLifecycleStatus,
     *     representative_status: KnowledgeDocumentStatus,
     *     document_type: KnowledgeDocumentType,
     *     updated_at: Carbon,
     *     title: string,
     *     effective_at: Carbon|null,
     * }>
     */
    private function buildChainSummaries(Deployment $deployment, Collection $chainRootIds): Collection
    {
        if ($chainRootIds->isEmpty()) {
            return collect();
        }

        $documents = KnowledgeDocument::query()
            ->where('deployment_id', $deployment->id)
            ->whereIn('chain_root_id', $chainRootIds->all())
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->get()
            ->groupBy('chain_root_id');

        return $chainRootIds
            ->map(function (int $chainRootId) use ($documents): ?array {
                $chainDocuments = $documents->get($chainRootId);

                if ($chainDocuments === null || $chainDocuments->isEmpty()) {
                    return null;
                }

                $active = $chainDocuments->first(
                    fn (KnowledgeDocument $document): bool => $document->lifecycle_status === KnowledgeDocumentLifecycleStatus::Active,
                );
                $head = $chainDocuments->first();
                $representative = $active ?? $head;

                $attentionDraft = $chainDocuments
                    ->filter(function (KnowledgeDocument $document) use ($active): bool {
                        if ($document->lifecycle_status !== KnowledgeDocumentLifecycleStatus::Draft) {
                            return false;
                        }

                        if ($document->status !== KnowledgeDocumentStatus::Ready) {
                            return false;
                        }

                        if ($active === null) {
                            return true;
                        }

                        return $document->revision_number > $active->revision_number;
                    })
                    ->sortByDesc('revision_number')
                    ->first();

                $needsAttention = $attentionDraft !== null;
                $attentionReason = $needsAttention ? 'draft_ready' : null;

                $isArchivedChain = $active !== null
                    ? $active->lifecycle_status === KnowledgeDocumentLifecycleStatus::Archived
                    : $head->lifecycle_status === KnowledgeDocumentLifecycleStatus::Archived;

                $hasProcessingFailure = $chainDocuments->contains(
                    fn (KnowledgeDocument $document): bool => $document->status === KnowledgeDocumentStatus::Failed,
                );

                $hasPendingDraft = $chainDocuments->contains(function (KnowledgeDocument $document): bool {
                    return $document->lifecycle_status === KnowledgeDocumentLifecycleStatus::Draft
                        && in_array($document->status, [
                            KnowledgeDocumentStatus::Pending,
                            KnowledgeDocumentStatus::Processing,
                        ], true);
                });

                return [
                    'chain_root_id' => $chainRootId,
                    'revision_count' => $chainDocuments->count(),
                    'active_id' => $active?->id,
                    'head_id' => $head->id,
                    'attention_draft_id' => $attentionDraft?->id,
                    'needs_attention' => $needsAttention,
                    'attention_reason' => $attentionReason,
                    'is_archived_chain' => $isArchivedChain,
                    'has_processing_failure' => $hasProcessingFailure,
                    'has_pending_draft' => $hasPendingDraft,
                    'representative_lifecycle_status' => $representative->lifecycle_status,
                    'representative_status' => $representative->status,
                    'document_type' => $representative->document_type,
                    'updated_at' => $chainDocuments->max('updated_at'),
                    'title' => $representative->title,
                    'effective_at' => $representative->effective_at,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $summaries
     * @return Collection<int, array<string, mixed>>
     */
    private function sortSummaries(Collection $summaries, string $sort, string $direction): Collection
    {
        $sorted = $summaries->sortBy(function (array $summary) use ($sort): mixed {
            return match ($sort) {
                'title' => strtolower((string) $summary['title']),
                'effective_at' => $summary['effective_at']?->timestamp ?? 0,
                default => $summary['updated_at']->timestamp,
            };
        }, SORT_REGULAR);

        if ($direction === 'desc') {
            return $sorted->reverse()->values();
        }

        return $sorted->values();
    }

    private function countNeedsAttentionChains(Deployment $deployment): int
    {
        $chainRootIds = KnowledgeDocument::query()
            ->where('deployment_id', $deployment->id)
            ->whereNotNull('chain_root_id')
            ->select('chain_root_id')
            ->distinct()
            ->pluck('chain_root_id');

        return $this->buildChainSummaries($deployment, $chainRootIds)
            ->filter(fn (array $summary): bool => $summary['needs_attention'])
            ->count();
    }
}
