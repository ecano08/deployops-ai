<?php

namespace App\Services;

class KnowledgeSearchService
{
    public function __construct(private AiServiceClient $aiService) {}

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

        return $this->aiService->searchKnowledge(
            $context->workspace->id,
            $context->customer->id,
            $context->deployment->id,
            $query,
            $topK,
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
