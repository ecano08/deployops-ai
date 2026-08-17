<?php

namespace App\Services;

use App\Models\KnowledgeDocument;

class KnowledgeSearchService
{
    public function __construct(
        private AiServiceClient $aiService,
        private KnowledgeQueryEnricher $queryEnricher,
    ) {}

    /**
     * @return array<int, array{
     *     document_id: int,
     *     source_filename: string,
     *     chunk_index: int,
     *     content: string,
     *     score: float
     * }>
     */
    public function search(CopilotContext $context, string $query, ?int $topK = null): array
    {
        $topK = $this->resolveTopK($topK);

        $eligibleDocumentIds = KnowledgeDocument::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('customer_id', $context->customer->id)
            ->where('deployment_id', $context->deployment->id)
            ->authoritativeForRag()
            ->pluck('id')
            ->all();

        if ($eligibleDocumentIds === []) {
            return [];
        }

        $enriched = $this->queryEnricher->enrich(
            $query,
            $context->currentQuestion,
            $context->userQuestionHistory,
        );

        return $this->aiService->searchKnowledge(
            $context->workspace->id,
            $context->customer->id,
            $context->deployment->id,
            $enriched['query'],
            $topK,
            $eligibleDocumentIds,
            $enriched['lexical_terms'],
        );
    }

    private function resolveTopK(?int $topK): int
    {
        $default = (int) config('services.knowledge.default_top_k', 5);
        $max = (int) config('services.knowledge.max_top_k', 20);

        if ($topK === null) {
            return $default;
        }

        return max(1, min($topK, $max));
    }
}
