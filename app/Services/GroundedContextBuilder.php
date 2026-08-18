<?php

namespace App\Services;

use App\Enums\GroundedContextKind;
use App\Enums\ProjectFactStatus;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class GroundedContextBuilder
{
    public function __construct(
        private KnowledgeSearchService $knowledgeSearch,
        private KnowledgeQueryEnricher $queryEnricher,
    ) {}

    public function build(User $user, Deployment $deployment, string $query): GroundedContextPackage
    {
        $query = trim($query);
        $terms = $this->queryTerms($query);

        $relevantFacts = $this->relevantVerifiedFacts($deployment, $terms);
        $relevantDocuments = $this->relevantDocumentChunks($user, $deployment, $query, $terms);

        $factPayloads = $this->serializeFacts($relevantFacts);
        $documentPayloads = $this->serializeDocuments($relevantDocuments);
        $conflicts = $this->detectConflicts($relevantFacts, $relevantDocuments);
        $unknowns = $this->detectUnknowns($query, $terms, $factPayloads, $documentPayloads);
        $sources = $this->collectSources($factPayloads, $documentPayloads);

        return new GroundedContextPackage(
            query: $query,
            facts: $factPayloads,
            documents: $documentPayloads,
            conflicts: $conflicts,
            unknowns: $unknowns,
            sources: $sources,
        );
    }

    /**
     * @return list<string>
     */
    private function queryTerms(string $query): array
    {
        return $this->queryEnricher->enrich($query, $query)['lexical_terms'];
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{fact: ProjectFact, score: float, grounding: GroundedContextKind}>
     */
    private function relevantVerifiedFacts(Deployment $deployment, array $terms): array
    {
        $minScore = (float) config('services.grounded_context.min_fact_score', 0.2);
        $strongScore = (float) config('services.grounded_context.strong_fact_score', 0.4);
        $maxFacts = (int) config('services.grounded_context.max_facts', 20);

        $facts = ProjectFact::query()
            ->forDeployment($deployment)
            ->where('status', ProjectFactStatus::Verified)
            ->with(['sourceDocument:id,title,revision_number,original_filename'])
            ->latest('verified_at')
            ->get();

        $ranked = [];

        foreach ($facts as $fact) {
            $score = $this->scoreText($this->factSearchText($fact), $terms);

            if ($score < $minScore) {
                continue;
            }

            $ranked[] = [
                'fact' => $fact,
                'score' => $score,
                'grounding' => $score >= $strongScore
                    ? GroundedContextKind::VerifiedFact
                    : GroundedContextKind::Inferred,
            ];
        }

        usort($ranked, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($ranked, 0, max(1, $maxFacts));
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{chunk: array{document_id: int, source_filename: string, chunk_index: int, content: string, score: float}, document: KnowledgeDocument, grounding: GroundedContextKind}>
     */
    private function relevantDocumentChunks(User $user, Deployment $deployment, string $query, array $terms): array
    {
        $deployment->loadMissing(['workspace', 'customer']);

        $eligibleDocuments = KnowledgeDocument::query()
            ->where('workspace_id', $deployment->workspace_id)
            ->where('customer_id', $deployment->customer_id)
            ->where('deployment_id', $deployment->id)
            ->authoritativeForRag()
            ->get()
            ->keyBy('id');

        if ($eligibleDocuments->isEmpty()) {
            return [];
        }

        $context = new CopilotContext(
            user: $user,
            workspace: $deployment->workspace,
            customer: $deployment->customer,
            deployment: $deployment,
            currentQuestion: $query,
        );

        $topK = (int) config('services.grounded_context.document_top_k', 10);
        $minScore = (float) config('services.grounded_context.min_document_score', 0.3);
        $strongScore = (float) config('services.grounded_context.strong_document_score', 0.6);
        $maxDocuments = (int) config('services.grounded_context.max_documents', 10);

        try {
            $chunks = $this->knowledgeSearch->search($context, $query, $topK);
        } catch (Throwable) {
            return [];
        }

        $ranked = [];

        foreach ($chunks as $chunk) {
            $document = $eligibleDocuments->get($chunk['document_id']);

            if (! $document instanceof KnowledgeDocument || ! $document->isAuthoritativeForRag()) {
                continue;
            }

            if ($chunk['score'] < $minScore) {
                continue;
            }

            $lexicalScore = $this->scoreText(
                $this->searchable($chunk['content'].' '.$document->title.' '.$document->original_filename),
                $terms,
            );

            if ($lexicalScore < (float) config('services.grounded_context.min_fact_score', 0.2)) {
                continue;
            }

            $ranked[] = [
                'chunk' => $chunk,
                'document' => $document,
                'grounding' => $chunk['score'] >= $strongScore
                    ? GroundedContextKind::Documented
                    : GroundedContextKind::Inferred,
            ];
        }

        usort(
            $ranked,
            fn (array $left, array $right): int => $right['chunk']['score'] <=> $left['chunk']['score'],
        );

        return array_slice($ranked, 0, max(1, $maxDocuments));
    }

    /**
     * @param  list<array{fact: ProjectFact, score: float, grounding: GroundedContextKind}>  $facts
     * @return list<array<string, mixed>>
     */
    private function serializeFacts(array $facts): array
    {
        return array_map(function (array $item): array {
            $fact = $item['fact'];

            return [
                'id' => $fact->id,
                'category' => $fact->category,
                'key' => $fact->key,
                'value' => $fact->value,
                'confidence' => $fact->confidence,
                'relevance' => round($item['score'], 4),
                'grounding' => $item['grounding']->value,
                'provenance' => [
                    'type' => 'project_fact',
                    'fact_id' => $fact->id,
                    'status' => ProjectFactStatus::Verified->value,
                    'source_document_id' => $fact->source_document_id,
                    'source_revision' => $fact->source_revision,
                    'source_reference' => $fact->source_reference,
                    'source_document' => $fact->sourceDocument === null ? null : [
                        'id' => $fact->sourceDocument->id,
                        'title' => $fact->sourceDocument->title,
                        'revision_number' => $fact->sourceDocument->revision_number,
                        'original_filename' => $fact->sourceDocument->original_filename,
                    ],
                    'verified_at' => $fact->verified_at?->toIso8601String(),
                ],
            ];
        }, $facts);
    }

    /**
     * @param  list<array{chunk: array{document_id: int, source_filename: string, chunk_index: int, content: string, score: float}, document: KnowledgeDocument, grounding: GroundedContextKind}>  $documents
     * @return list<array<string, mixed>>
     */
    private function serializeDocuments(array $documents): array
    {
        return array_map(function (array $item): array {
            $chunk = $item['chunk'];
            $document = $item['document'];

            return [
                'document_id' => $document->id,
                'title' => $document->title,
                'source_filename' => $chunk['source_filename'],
                'revision_number' => $document->revision_number,
                'chunk_index' => $chunk['chunk_index'],
                'content' => $chunk['content'],
                'score' => $chunk['score'],
                'grounding' => $item['grounding']->value,
                'provenance' => [
                    'type' => 'knowledge_document',
                    'document_id' => $document->id,
                    'title' => $document->title,
                    'original_filename' => $document->original_filename,
                    'revision_number' => $document->revision_number,
                    'chunk_index' => $chunk['chunk_index'],
                    'lifecycle_status' => $document->lifecycle_status->value,
                    'status' => $document->status->value,
                ],
            ];
        }, $documents);
    }

    /**
     * @param  list<array{fact: ProjectFact, score: float, grounding: GroundedContextKind}>  $facts
     * @param  list<array{chunk: array{document_id: int, source_filename: string, chunk_index: int, content: string, score: float}, document: KnowledgeDocument, grounding: GroundedContextKind}>  $documents
     * @return list<array<string, mixed>>
     */
    private function detectConflicts(array $facts, array $documents): array
    {
        $conflicts = [];

        foreach ($this->conflictingVerifiedFacts($facts) as $conflict) {
            $conflicts[] = $conflict;
        }

        foreach ($facts as $item) {
            $fact = $item['fact'];
            $factQuantities = $this->extractQuantities($fact->value);

            if ($factQuantities === []) {
                continue;
            }

            foreach ($documents as $documentItem) {
                $chunk = $documentItem['chunk'];

                if (! $this->factTopicAppearsInText($fact, $chunk['content'])) {
                    continue;
                }

                if ($this->normalizedContains($chunk['content'], $fact->value)) {
                    continue;
                }

                $chunkQuantities = $this->extractQuantities($chunk['content']);
                $mismatched = $this->mismatchedQuantities($factQuantities, $chunkQuantities);

                if ($mismatched === []) {
                    continue;
                }

                $conflicts[] = [
                    'grounding' => GroundedContextKind::Conflicting->value,
                    'topic' => $fact->category.'.'.$fact->key,
                    'summary' => sprintf(
                        'Verified fact "%s" is %s, but active documentation describes %s.',
                        $fact->category.'.'.$fact->key,
                        $fact->value,
                        $mismatched[0]['document_value'],
                    ),
                    'fact_ids' => [$fact->id],
                    'document_ids' => [$documentItem['document']->id],
                    'items' => [
                        [
                            'type' => 'project_fact',
                            'id' => $fact->id,
                            'value' => $fact->value,
                        ],
                        [
                            'type' => 'knowledge_document',
                            'document_id' => $documentItem['document']->id,
                            'chunk_index' => $chunk['chunk_index'],
                            'excerpt' => Str::limit($chunk['content'], 240),
                        ],
                    ],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<array{fact: ProjectFact, score: float, grounding: GroundedContextKind}>  $facts
     * @return list<array<string, mixed>>
     */
    private function conflictingVerifiedFacts(array $facts): array
    {
        /** @var Collection<string, Collection<int, array{fact: ProjectFact, score: float, grounding: GroundedContextKind}>> $grouped */
        $grouped = collect($facts)->groupBy(
            fn (array $item): string => Str::lower($item['fact']->category).'|'.Str::lower($item['fact']->key),
        );

        $conflicts = [];

        foreach ($grouped as $items) {
            $uniqueValues = $items
                ->map(fn (array $item): string => Str::lower(Str::squish($item['fact']->value)))
                ->unique()
                ->values();

            if ($uniqueValues->count() < 2) {
                continue;
            }

            /** @var ProjectFact $first */
            $first = $items->first()['fact'];

            $conflicts[] = [
                'grounding' => GroundedContextKind::Conflicting->value,
                'topic' => $first->category.'.'.$first->key,
                'summary' => sprintf(
                    'Multiple verified facts disagree about %s.',
                    $first->category.'.'.$first->key,
                ),
                'fact_ids' => $items->map(fn (array $item): int => $item['fact']->id)->values()->all(),
                'document_ids' => [],
                'items' => $items->map(fn (array $item): array => [
                    'type' => 'project_fact',
                    'id' => $item['fact']->id,
                    'value' => $item['fact']->value,
                ])->values()->all(),
            ];
        }

        return $conflicts;
    }

    /**
     * @param  list<string>  $terms
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function detectUnknowns(string $query, array $terms, array $facts, array $documents): array
    {
        $evidenceText = Str::lower(collect($facts)
            ->map(fn (array $fact): string => $this->searchable($fact['category'].' '.$fact['key'].' '.$fact['value']))
            ->merge(collect($documents)->map(fn (array $document): string => $this->searchable((string) $document['content'])))
            ->implode(' '));

        if ($facts === [] && $documents === []) {
            return [[
                'grounding' => GroundedContextKind::Unknown->value,
                'topic' => $query,
                'reason' => 'No verified facts or active, ready documentation matched this question.',
            ]];
        }

        $uncovered = [];

        foreach ($terms as $term) {
            if (mb_strlen($term) < 4) {
                continue;
            }

            if (str_contains($evidenceText, Str::lower($term))) {
                continue;
            }

            $uncovered[] = $term;
        }

        if ($uncovered === []) {
            return [];
        }

        return [[
            'grounding' => GroundedContextKind::Unknown->value,
            'topic' => implode(', ', $uncovered),
            'reason' => 'No verified fact or active documentation covered: '.implode(', ', $uncovered).'.',
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function collectSources(array $facts, array $documents): array
    {
        $sources = [];
        $seen = [];

        foreach ($facts as $fact) {
            $key = 'project_fact:'.$fact['id'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $sources[] = [
                'type' => 'project_fact',
                'id' => $fact['id'],
                'label' => $fact['category'].'.'.$fact['key'],
                'status' => ProjectFactStatus::Verified->value,
                'source_document_id' => $fact['provenance']['source_document_id'],
                'source_revision' => $fact['provenance']['source_revision'],
            ];
        }

        foreach ($documents as $document) {
            $key = 'knowledge_document:'.$document['document_id'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $sources[] = [
                'type' => 'knowledge_document',
                'id' => $document['document_id'],
                'title' => $document['title'],
                'revision_number' => $document['revision_number'],
                'original_filename' => $document['source_filename'],
                'lifecycle_status' => $document['provenance']['lifecycle_status'],
                'status' => $document['provenance']['status'],
            ];
        }

        return $sources;
    }

    private function factSearchText(ProjectFact $fact): string
    {
        return $this->searchable(implode(' ', array_filter([
            $fact->category,
            $fact->key,
            $fact->value,
            $fact->source_reference,
        ])));
    }

    /**
     * @param  list<string>  $terms
     */
    private function scoreText(string $haystack, array $terms): float
    {
        if ($terms === [] || $haystack === '') {
            return 0.0;
        }

        $matched = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, Str::lower($term))) {
                $matched++;
            }
        }

        return $matched / count($terms);
    }

    private function factTopicAppearsInText(ProjectFact $fact, string $text): bool
    {
        $haystack = $this->searchable($text);
        $topicTerms = array_values(array_filter([
            Str::lower(str_replace(['_', '-'], ' ', $fact->category)),
            ...preg_split('/[_\-\s]+/', Str::lower($fact->key)) ?: [],
        ]));

        $matched = 0;

        foreach ($topicTerms as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }

            if (str_contains($haystack, $term)) {
                $matched++;
            }
        }

        return $matched >= 1;
    }

    private function normalizedContains(string $haystack, string $needle): bool
    {
        return str_contains($this->searchable($haystack), $this->searchable($needle));
    }

    private function searchable(string $text): string
    {
        return Str::lower(str_replace(['_', '-'], ' ', Str::squish($text)));
    }

    /**
     * @return list<array{number: float, unit: string, value: string}>
     */
    private function extractQuantities(string $text): array
    {
        if (preg_match_all(
            '/\b(\d+(?:\.\d+)?)\s*(minutes?|min|hours?|hrs?|days?|weeks?|months?|seconds?|secs?|%|percent)?\b/iu',
            $text,
            $matches,
            PREG_SET_ORDER,
        ) === false) {
            return [];
        }

        $quantities = [];

        foreach ($matches as $match) {
            $unit = Str::lower($match[2] ?? '');
            $unit = match ($unit) {
                'min', 'minute', 'minutes' => 'minutes',
                'hr', 'hrs', 'hour', 'hours' => 'hours',
                'sec', 'secs', 'second', 'seconds' => 'seconds',
                'day', 'days' => 'days',
                'week', 'weeks' => 'weeks',
                'month', 'months' => 'months',
                '%', 'percent' => 'percent',
                default => $unit,
            };

            $quantities[] = [
                'number' => (float) $match[1],
                'unit' => $unit,
                'value' => trim($match[0]),
            ];
        }

        return $quantities;
    }

    /**
     * @param  list<array{number: float, unit: string, value: string}>  $factQuantities
     * @param  list<array{number: float, unit: string, value: string}>  $documentQuantities
     * @return list<array{fact_value: string, document_value: string}>
     */
    private function mismatchedQuantities(array $factQuantities, array $documentQuantities): array
    {
        $mismatches = [];

        foreach ($factQuantities as $factQuantity) {
            $sameUnit = array_values(array_filter(
                $documentQuantities,
                fn (array $quantity): bool => $quantity['unit'] === $factQuantity['unit'] && $quantity['unit'] !== '',
            ));

            if ($sameUnit === []) {
                continue;
            }

            $hasMatchingNumber = false;

            foreach ($sameUnit as $documentQuantity) {
                if (abs($documentQuantity['number'] - $factQuantity['number']) < 0.0001) {
                    $hasMatchingNumber = true;
                    break;
                }
            }

            if ($hasMatchingNumber) {
                continue;
            }

            $mismatches[] = [
                'fact_value' => $factQuantity['value'],
                'document_value' => $sameUnit[0]['value'],
            ];
        }

        return $mismatches;
    }
}
