<?php

namespace App\Services;

use App\Enums\ProjectFactExtractionStatus;
use App\Exceptions\CopilotException;
use App\Jobs\ExtractProjectFactsJob;
use App\Models\KnowledgeDocument;
use App\Models\ProjectFact;
use App\Models\ProjectFactExtraction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

class ProjectFactExtractionService
{
    /** @var list<string> */
    private const CRITICAL_VALUE_TERMS = [
        'caducidad',
        'conflict',
        'expiration',
        'expire',
        'expired',
        'expires',
        'expiracion',
        'hold',
        'liberacion',
        'prioridad',
        'priority',
        'release',
        'released',
        'retry',
        'sla',
        'state transition',
        'threshold',
        'timeout',
        'umbral',
        'vencimiento',
    ];

    /** @var list<string> */
    private const HIGH_VALUE_TERMS = [
        'architecture',
        'arquitectura',
        'authorization',
        'authorisation',
        'autorizacion',
        'business rule',
        'config',
        'configuration',
        'constraint',
        'database',
        'framework',
        'integracion',
        'integration',
        'limit',
        'limite',
        'operational',
        'permission',
        'permiso',
        'provider',
        'proveedor',
        'rbac',
        'regla de negocio',
        'restriccion',
        'webhook',
        'workflow',
        'flujo',
    ];

    /** @var list<string> */
    private const LOW_VALUE_TERMS = [
        'almacen',
        'courier',
        'ejemplo',
        'empaque',
        'envio',
        'example',
        'genero',
        'genre',
        'illustration',
        'ilustracion',
        'logistica',
        'logistics',
        'packaging',
        'sample',
        'shipping',
        'subcategoria',
        'subcategory',
        'taxonomia',
        'taxonomy',
        'warehouse',
    ];

    /** @var list<string> */
    private const LOW_VALUE_FAMILY_TERMS = [
        'almacen',
        'catalog',
        'catalogo',
        'catalogue',
        'courier',
        'empaque',
        'envio',
        'genero',
        'genre',
        'logistica',
        'logistics',
        'packaging',
        'shipping',
        'subcategoria',
        'subcategory',
        'taxonomia',
        'taxonomy',
        'warehouse',
    ];

    /** @var list<string> */
    private const RESERVATION_TERMS = [
        'reservation',
        'reservations',
        'reserva',
        'reservas',
        'reserved',
        'reservado',
    ];

    /** @var list<string> */
    private const RESERVATION_TIMEBOX_TERMS = [
        'day',
        'days',
        'dia',
        'dias',
        'hora',
        'horas',
        'hour',
        'hours',
        'minute',
        'minutes',
        'minuto',
        'minutos',
        'second',
        'seconds',
        'segundo',
        'segundos',
        'semana',
        'semanas',
        'week',
        'weeks',
    ];

    /** @var list<string> */
    private const BEHAVIORAL_VALUE_TERMS = [
        'cannot',
        'forbidden',
        'must',
        'required',
        'shall',
    ];

    public function __construct(
        private OpenAIResponsesClient $openAI,
        private ProjectFactService $projectFactService,
        private KnowledgeDocumentContentService $contentService,
        private AiServiceClient $aiService,
    ) {}

    public function queue(User $creator, KnowledgeDocument $document): ProjectFactExtraction
    {
        if (! $document->isAuthoritativeForRag()) {
            throw new CopilotException(
                'Facts can only be extracted from active, ready project documentation.',
                422,
            );
        }

        if ($document->deployment_id === null) {
            throw new CopilotException('Source document is missing deployment scope.', 422);
        }

        $existing = ProjectFactExtraction::query()
            ->where('knowledge_document_id', $document->id)
            ->inFlight()
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $extraction = ProjectFactExtraction::query()->create([
            'workspace_id' => $document->workspace_id,
            'customer_id' => $document->customer_id,
            'deployment_id' => $document->deployment_id,
            'knowledge_document_id' => $document->id,
            'source_revision' => $document->revision_number,
            'created_by' => $creator->id,
            'status' => ProjectFactExtractionStatus::Pending,
        ]);

        ExtractProjectFactsJob::dispatch($extraction);

        return $extraction;
    }

    /**
     * @return list<array{
     *     id: int,
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string|null,
     *     confidence: float|null,
     *     status: string
     * }>
     */
    public function extractAndPropose(User $creator, KnowledgeDocument $document): array
    {
        if (! $document->isAuthoritativeForRag()) {
            throw new CopilotException(
                'Facts can only be extracted from active, ready project documentation.',
                422,
            );
        }

        $content = $this->resolveExtractionContent($document);
        $extractedFacts = $this->extractFactsFromContent($document, $content);
        $facts = $this->projectFactService->proposeExtractedFacts($creator, $document, $extractedFacts);

        return array_map(
            fn ($fact): array => [
                'id' => $fact->id,
                'category' => $fact->category,
                'key' => $fact->key,
                'value' => $fact->value,
                'source_reference' => $fact->source_reference,
                'confidence' => $fact->confidence,
                'status' => $fact->status->value,
            ],
            $facts,
        );
    }

    /**
     * @return array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>,
     *     source: string
     * }
     */
    public function resolveExtractionContent(KnowledgeDocument $document): array
    {
        $previewFormat = $this->contentService->resolvePreviewFormat($document);
        $requiresProcessedChunks = $previewFormat === 'pdf' || $document->chunk_count > 0;

        if ($requiresProcessedChunks) {
            $chunks = $this->fetchProcessedChunks($document);

            if ($chunks === []) {
                throw new CopilotException(
                    'Processed document content is unavailable for fact extraction.',
                    422,
                );
            }

            return [
                'text' => $this->buildChunkedDocumentText($chunks),
                'chunks' => $chunks,
                'source' => 'ai_service_chunks',
            ];
        }

        $text = $this->readLocalDocumentText($document);

        if ($text === null || trim($text) === '') {
            throw new CopilotException('Document content is unavailable for fact extraction.', 422);
        }

        return [
            'text' => $text,
            'chunks' => [],
            'source' => 'local_file',
        ];
    }

    /**
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>,
     *     source: string
     * }  $content
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null
     * }>
     */
    public function extractFactsFromContent(KnowledgeDocument $document, array $content): array
    {
        $extractedFacts = [];
        $batches = $this->buildExtractionBatches($content);
        $batchCount = count($batches);

        foreach ($batches as $index => $batch) {
            $extractedFacts = array_merge(
                $extractedFacts,
                $this->extractFactsFromBatch($document, $batch, $index + 1, $batchCount),
            );
        }

        return $this->capExtractedFacts(
            $this->rankExtractedFacts(
                $this->mergeEquivalentFacts(
                    $this->filterGroundedHighConfidenceFacts($extractedFacts, $content),
                ),
            ),
        );
    }

    /**
     * @return list<array{chunk_index: int, source_filename: string, content: string}>
     */
    private function fetchProcessedChunks(KnowledgeDocument $document): array
    {
        try {
            return $this->aiService->listDocumentChunks($document);
        } catch (ConnectionException) {
            throw new CopilotException(
                'Processed document content is unavailable for fact extraction.',
                503,
            );
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                return [];
            }

            throw new CopilotException(
                'Processed document content is unavailable for fact extraction.',
                422,
            );
        }
    }

    /**
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>,
     *     source: string
     * }  $content
     * @return list<array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>
     * }>
     */
    private function buildExtractionBatches(array $content): array
    {
        if ($content['chunks'] === []) {
            return $this->splitLocalTextBatches($content['text']);
        }

        $batchSize = max(1, (int) config('services.project_facts.extraction_chunk_batch_size', 4));
        $maxCharacters = max(1000, (int) config('services.project_facts.extraction_max_batch_characters', 6000));
        $batches = [];
        $currentChunks = [];
        $currentCharacters = 0;

        foreach ($content['chunks'] as $chunk) {
            $chunkCharacters = Str::length($chunk['content']);
            $exceedsCount = $currentChunks !== [] && count($currentChunks) >= $batchSize;
            $exceedsCharacters = $currentChunks !== [] && ($currentCharacters + $chunkCharacters) > $maxCharacters;

            if ($exceedsCount || $exceedsCharacters) {
                $batches[] = $this->makeChunkBatch($currentChunks);
                $currentChunks = [];
                $currentCharacters = 0;
            }

            $currentChunks[] = $chunk;
            $currentCharacters += $chunkCharacters;
        }

        if ($currentChunks !== []) {
            $batches[] = $this->makeChunkBatch($currentChunks);
        }

        return $batches;
    }

    /**
     * @return list<array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>
     * }>
     */
    private function splitLocalTextBatches(string $text): array
    {
        $maxCharacters = max(1000, (int) config('services.project_facts.extraction_max_batch_characters', 6000));

        if (Str::length($text) <= $maxCharacters) {
            return [[
                'text' => $text,
                'chunks' => [],
            ]];
        }

        $batches = [];
        $remaining = $text;

        while ($remaining !== '') {
            if (Str::length($remaining) <= $maxCharacters) {
                $batches[] = [
                    'text' => $remaining,
                    'chunks' => [],
                ];
                break;
            }

            $window = Str::substr($remaining, 0, $maxCharacters);
            $splitAt = strrpos($window, "\n\n");

            if (! is_int($splitAt) || $splitAt < (int) ($maxCharacters * 0.5)) {
                $splitAt = $maxCharacters;
            }

            $batches[] = [
                'text' => trim(Str::substr($remaining, 0, $splitAt)),
                'chunks' => [],
            ];
            $remaining = trim(Str::substr($remaining, $splitAt));
        }

        return array_values(array_filter(
            $batches,
            static fn (array $batch): bool => $batch['text'] !== '',
        ));
    }

    /**
     * @param  list<array{chunk_index: int, source_filename: string, content: string}>  $chunks
     * @return array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>
     * }
     */
    private function makeChunkBatch(array $chunks): array
    {
        return [
            'text' => $this->buildChunkedDocumentText($chunks),
            'chunks' => $chunks,
        ];
    }

    /**
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>
     * }  $batch
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null
     * }>
     */
    private function extractFactsFromBatch(
        KnowledgeDocument $document,
        array $batch,
        int $batchNumber,
        int $batchCount,
    ): array {
        $decoded = $this->requestBatchFacts($document, $batch);

        if ($decoded['ok']) {
            return $this->normalizeExtractedFacts($decoded['facts'], $batch['chunks']);
        }

        $this->logBatchFailure($document, $batch, $batchNumber, $batchCount, $decoded, attempt: 1);

        $decoded = $this->requestBatchFacts($document, $batch);

        if ($decoded['ok']) {
            return $this->normalizeExtractedFacts($decoded['facts'], $batch['chunks']);
        }

        $this->logBatchFailure($document, $batch, $batchNumber, $batchCount, $decoded, attempt: 2);

        throw new CopilotException(
            "The AI service returned invalid extraction output for batch {$batchNumber} of {$batchCount}.",
            502,
        );
    }

    /**
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>
     * }  $batch
     * @return array{ok: true, facts: list<mixed>}|array{
     *     ok: false,
     *     reason: string,
     *     json_error: string|null,
     *     response_status: string|null,
     *     incomplete_reason: string|null
     * }
     */
    private function requestBatchFacts(KnowledgeDocument $document, array $batch): array
    {
        $chunkRange = $this->describeChunkRange($batch['chunks']);
        $instructions = <<<PROMPT
You extract structured project facts from governed documentation.

Rules:
- Analyze every labeled chunk in this batch. Do not stop after the first chunk.
- Return facts only when they are explicitly supported by the provided text.
- Do not invent, infer, or generalize facts that are not explicitly stated.
- Keep only explicit, high-confidence statements (confidence 0.7 or higher).
- Skip boilerplate, headings, page furniture, and trivial restatements.
- Prioritize facts useful for engineering, business rules, integrations, architecture, and operational behavior.
- Prefer business rules, workflows and state transitions, permissions and authorization, integration and provider behavior, configuration values, timeouts limits and thresholds, architecture and technical decisions, operational requirements, and important constraints.
- Critical timeouts, expiration and release behavior, state transitions, and conflict priority rules must be extracted even when they appear in later chunks.
- Preserve workflow scope in keys. Distinct workflows must never share a key or be merged.
- Cart checkout reservations and WhatsApp holds are different workflows and must remain separate facts.
- Example scoped keys: reservation.cart.timeout, reservation.cart.expiration_behavior, reservation.whatsapp.hold_duration, reservation.conflict.priority.
- Deprioritize exhaustive taxonomy or catalog list items such as individual subcategories, examples, repeated descriptive details, and trivial facts that do not affect system behavior.
- Deprioritize numerous logistics and operational details such as shipping, packaging, warehouses, and couriers when they would displace timeouts, release rules, or priority rules.
- Skip low-value list items even when they are explicit, unless they uniquely change system behavior.
- Every fact must include a direct source_reference excerpt copied or closely paraphrased from the supporting text.
- When content is labeled with chunk numbers, include source_chunk_index for the supporting chunk. Use null when the supporting chunk is unknown.
- Use dot-notation keys grouped by category (examples: framework.backend, database.primary, authorization.model, reservation.cart.timeout).
- Never modify the documentation.
- Facts are proposals only; do not mark anything as verified.
- Return at most 12 of the highest-value explicit facts for this batch.
- Return an empty facts list when this batch contains no explicit high-confidence facts.
{$chunkRange}
PROMPT;

        $input = $this->openAI->userMessageItem(
            "Document title: {$document->title}\n".
            "Revision: {$document->revision_number}\n\n".
            "Document content:\n\n{$batch['text']}",
        );

        $response = $this->openAI->create(
            $instructions,
            [$input],
            [$this->proposeFactsToolDefinition()],
            [
                'max_output_tokens' => (int) config('services.project_facts.extraction_max_output_tokens', 4096),
                'timeout' => (int) config('services.project_facts.extraction_timeout', 60),
                'tool_choice' => [
                    'type' => 'function',
                    'name' => 'propose_project_facts',
                ],
            ],
        );

        return $this->decodeBatchResponse($response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{ok: true, facts: list<mixed>}|array{
     *     ok: false,
     *     reason: string,
     *     json_error: string|null,
     *     response_status: string|null,
     *     incomplete_reason: string|null
     * }
     */
    private function decodeBatchResponse(array $response): array
    {
        $responseStatus = is_string($response['status'] ?? null) ? $response['status'] : null;
        $incompleteReason = data_get($response, 'incomplete_details.reason');
        $incompleteReason = is_string($incompleteReason) ? $incompleteReason : null;
        $failure = fn (string $reason, ?string $jsonError = null): array => [
            'ok' => false,
            'reason' => $reason,
            'json_error' => $jsonError,
            'response_status' => $responseStatus,
            'incomplete_reason' => $incompleteReason,
        ];

        if ($responseStatus === 'incomplete') {
            return $failure('incomplete_output');
        }

        $functionCalls = $this->openAI->extractFunctionCalls($response);

        if ($functionCalls === []) {
            return ['ok' => true, 'facts' => []];
        }

        $arguments = $this->unwrapToolArguments((string) ($functionCalls[0]['arguments'] ?? '{}'));

        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $failure('invalid_json', $exception->getMessage());
        }

        if (! is_array($decoded)) {
            return $failure('invalid_payload');
        }

        if (! array_key_exists('facts', $decoded)) {
            return $failure('missing_facts');
        }

        if ($decoded['facts'] === null) {
            return ['ok' => true, 'facts' => []];
        }

        if (! is_array($decoded['facts'])) {
            return $failure('facts_not_array');
        }

        $facts = array_is_list($decoded['facts'])
            ? $decoded['facts']
            : array_values($decoded['facts']);

        return ['ok' => true, 'facts' => $facts];
    }

    /**
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>
     * }  $batch
     * @param  array{
     *     ok: false,
     *     reason: string,
     *     json_error: string|null,
     *     response_status: string|null,
     *     incomplete_reason: string|null
     * }  $failure
     */
    private function logBatchFailure(
        KnowledgeDocument $document,
        array $batch,
        int $batchNumber,
        int $batchCount,
        array $failure,
        int $attempt,
    ): void {
        $chunkIndexes = array_map(
            static fn (array $chunk): int => $chunk['chunk_index'],
            $batch['chunks'],
        );

        Log::warning('Project fact extraction batch failed.', [
            'document_id' => $document->id,
            'source_revision' => $document->revision_number,
            'workspace_id' => $document->workspace_id,
            'customer_id' => $document->customer_id,
            'deployment_id' => $document->deployment_id,
            'batch_number' => $batchNumber,
            'batch_count' => $batchCount,
            'attempt' => $attempt,
            'reason' => $failure['reason'],
            'json_error' => $failure['json_error'],
            'response_status' => $failure['response_status'],
            'incomplete_reason' => $failure['incomplete_reason'],
            'chunk_count' => count($batch['chunks']),
            'chunk_index_start' => $chunkIndexes === [] ? null : min($chunkIndexes),
            'chunk_index_end' => $chunkIndexes === [] ? null : max($chunkIndexes),
        ]);
    }

    private function unwrapToolArguments(string $arguments): string
    {
        $trimmed = trim($arguments);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
            $trimmed = (string) preg_replace('/\s*```$/', '', $trimmed);
            $trimmed = trim($trimmed);
        }

        return $trimmed;
    }

    /**
     * @param  list<array{chunk_index: int, source_filename: string, content: string}>  $chunks
     */
    private function describeChunkRange(array $chunks): string
    {
        if ($chunks === []) {
            return '';
        }

        $indexes = array_map(
            static fn (array $chunk): int => $chunk['chunk_index'],
            $chunks,
        );

        $first = min($indexes);
        $last = max($indexes);

        return "- This batch covers chunks {$first} through {$last}. Extract facts from all of them.";
    }

    /**
     * @param  list<array{chunk_index: int, source_filename: string, content: string}>  $chunks
     */
    private function buildChunkedDocumentText(array $chunks): string
    {
        $sections = [];

        foreach ($chunks as $chunk) {
            $sections[] = "[Chunk {$chunk['chunk_index']}]\n{$chunk['content']}";
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return array{type: string, name: string, description: string, strict: bool, parameters: array<string, mixed>}
     */
    private function proposeFactsToolDefinition(): array
    {
        $factProperties = [
            'category' => ['type' => 'string'],
            'key' => ['type' => 'string'],
            'value' => ['type' => 'string'],
            'source_reference' => ['type' => 'string'],
            'confidence' => ['type' => ['number', 'null']],
            'source_chunk_index' => ['type' => ['integer', 'null']],
        ];

        return [
            'type' => 'function',
            'name' => 'propose_project_facts',
            'description' => 'Propose structured project facts extracted from every provided documentation chunk with evidence.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'facts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => $factProperties,
                            'required' => array_keys($factProperties),
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['facts'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $facts
     * @param  list<array{chunk_index: int, source_filename: string, content: string}>  $chunks
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null
     * }>
     */
    private function normalizeExtractedFacts(array $facts, array $chunks): array
    {
        $normalized = [];
        $validChunkIndexes = array_map(
            static fn (array $chunk): int => $chunk['chunk_index'],
            $chunks,
        );

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $category = $this->normalizeRequiredString($fact['category'] ?? null);
            $key = $this->normalizeRequiredString($fact['key'] ?? null);
            $value = $this->normalizeRequiredString($fact['value'] ?? null);
            $sourceReference = $this->normalizeRequiredString($fact['source_reference'] ?? null);

            if ($category === null || $key === null || $value === null || $sourceReference === null) {
                continue;
            }

            $sourceReference = $this->stripChunkPrefix($sourceReference);
            $confidence = $this->normalizeOptionalFloat($fact['confidence'] ?? null);
            $sourceChunkIndex = $this->normalizeOptionalInteger($fact['source_chunk_index'] ?? null);

            if ($sourceReference === '') {
                continue;
            }

            if ($confidence !== null) {
                $confidence = max(0.0, min(1.0, $confidence));
            }

            $key = $this->applyWorkflowScopedKey($category, $key, $value, $sourceReference);

            if ($sourceChunkIndex !== null && ! in_array($sourceChunkIndex, $validChunkIndexes, true)) {
                $sourceChunkIndex = null;
            }

            if ($sourceChunkIndex === null && $chunks !== []) {
                $sourceChunkIndex = $this->inferChunkIndex($sourceReference, $chunks);
            }

            if ($sourceChunkIndex !== null) {
                $sourceReference = "[Chunk {$sourceChunkIndex}] {$sourceReference}";
            }

            $entry = [
                'category' => $category,
                'key' => $key,
                'value' => $value,
                'source_reference' => $sourceReference,
                'confidence' => $confidence,
            ];

            if ($sourceChunkIndex !== null) {
                $entry['source_chunk_index'] = $sourceChunkIndex;
            }

            if ($chunks !== []) {
                $entry['content_source'] = 'ai_service_chunks';
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>  $facts
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>,
     *     source: string
     * }  $content
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>
     */
    private function filterGroundedHighConfidenceFacts(array $facts, array $content): array
    {
        $minimumConfidence = (float) config('services.project_facts.extraction_min_confidence', 0.7);
        $filtered = [];

        foreach ($facts as $fact) {
            $confidence = $fact['confidence'] ?? null;

            if ($confidence !== null && $confidence < $minimumConfidence) {
                continue;
            }

            if (! $this->isFactGrounded($fact, $content)) {
                continue;
            }

            $filtered[] = $fact;
        }

        return $filtered;
    }

    /**
     * @param  array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null
     * }  $fact
     * @param  array{
     *     text: string,
     *     chunks: list<array{chunk_index: int, source_filename: string, content: string}>,
     *     source: string
     * }  $content
     */
    private function isFactGrounded(array $fact, array $content): bool
    {
        $excerpt = $this->stripChunkPrefix($fact['source_reference']);

        if ($content['chunks'] === []) {
            return $this->excerptAppearsIn($excerpt, $content['text']);
        }

        $chunkIndex = $fact['source_chunk_index'] ?? null;
        $candidateChunks = $content['chunks'];

        if ($chunkIndex !== null) {
            $candidateChunks = array_values(array_filter(
                $content['chunks'],
                static fn (array $chunk): bool => $chunk['chunk_index'] === $chunkIndex,
            ));

            if ($candidateChunks === []) {
                return false;
            }
        }

        foreach ($candidateChunks as $chunk) {
            if ($this->excerptAppearsIn($excerpt, $chunk['content'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{chunk_index: int, source_filename: string, content: string}>  $chunks
     */
    private function inferChunkIndex(string $excerpt, array $chunks): ?int
    {
        foreach ($chunks as $chunk) {
            if ($this->excerptAppearsIn($excerpt, $chunk['content'])) {
                return $chunk['chunk_index'];
            }
        }

        return null;
    }

    private function excerptAppearsIn(string $excerpt, string $content): bool
    {
        $normalizedExcerpt = Str::lower(Str::squish($excerpt));
        $normalizedContent = Str::lower(Str::squish($content));

        if ($normalizedExcerpt === '' || $normalizedContent === '') {
            return false;
        }

        if (str_contains($normalizedContent, $normalizedExcerpt)) {
            return true;
        }

        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9.]+/i', $normalizedExcerpt) ?: [],
            static fn (string $token): bool => Str::length($token) >= 4,
        ));

        if ($tokens === []) {
            return false;
        }

        $matched = 0;

        foreach ($tokens as $token) {
            if (str_contains($normalizedContent, Str::lower($token))) {
                $matched++;
            }
        }

        return ($matched / count($tokens)) >= 0.7;
    }

    private function normalizeRequiredString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeOptionalInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function normalizeOptionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return is_nan($number) || is_infinite($number) ? null : $number;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function stripChunkPrefix(string $reference): string
    {
        return trim((string) preg_replace('/^\[Chunk \d+\]\s*/', '', $reference));
    }

    /**
     * @param  list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>  $facts
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>
     */
    private function mergeEquivalentFacts(array $facts): array
    {
        $merged = [];
        $indexesByFingerprint = [];

        foreach ($facts as $fact) {
            $fingerprint = $this->factFingerprint($fact);

            if (isset($indexesByFingerprint[$fingerprint])) {
                $existingIndex = $indexesByFingerprint[$fingerprint];
                $merged[$existingIndex] = $this->preferEquivalentFact($merged[$existingIndex], $fact);

                continue;
            }

            $indexesByFingerprint[$fingerprint] = count($merged);
            $merged[] = $fact;
        }

        return $merged;
    }

    /**
     * @param  array{category: string, key: string, value: string}  $fact
     */
    private function factFingerprint(array $fact): string
    {
        return ProjectFact::equivalentFingerprint($fact['category'], $fact['key'], $fact['value']);
    }

    /**
     * @param  array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }  $current
     * @param  array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }  $candidate
     * @return array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }
     */
    private function preferEquivalentFact(array $current, array $candidate): array
    {
        $currentConfidence = $current['confidence'] ?? 0.0;
        $candidateConfidence = $candidate['confidence'] ?? 0.0;

        if ($candidateConfidence > $currentConfidence) {
            return $candidate;
        }

        if ($candidateConfidence < $currentConfidence) {
            return $current;
        }

        $currentChunk = $current['source_chunk_index'] ?? PHP_INT_MAX;
        $candidateChunk = $candidate['source_chunk_index'] ?? PHP_INT_MAX;

        return $candidateChunk < $currentChunk ? $candidate : $current;
    }

    /**
     * @param  list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>  $facts
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>
     */
    private function rankExtractedFacts(array $facts): array
    {
        $familyCounts = $this->lowValueFamilyCounts($facts);
        $ranked = $facts;

        usort($ranked, function (array $left, array $right) use ($familyCounts): int {
            $relevanceComparison = $this->engineeringRelevanceScore($right, $familyCounts)
                <=> $this->engineeringRelevanceScore($left, $familyCounts);

            if ($relevanceComparison !== 0) {
                return $relevanceComparison;
            }

            $confidenceComparison = ($right['confidence'] ?? 0.0) <=> ($left['confidence'] ?? 0.0);

            if ($confidenceComparison !== 0) {
                return $confidenceComparison;
            }

            return ($left['source_chunk_index'] ?? PHP_INT_MAX) <=> ($right['source_chunk_index'] ?? PHP_INT_MAX);
        });

        return array_values($ranked);
    }

    /**
     * @param  list<array{category: string, key: string, value: string}>  $facts
     * @return array<string, int>
     */
    private function lowValueFamilyCounts(array $facts): array
    {
        $counts = [];

        foreach ($facts as $fact) {
            $family = $this->lowValueFamilyKey($fact);

            if ($family === null) {
                continue;
            }

            $counts[$family] = ($counts[$family] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array{category: string, key: string, value: string}  $fact
     */
    private function lowValueFamilyKey(array $fact): ?string
    {
        $searchText = $this->factSearchText($fact);

        if (! $this->searchTextContainsAny($searchText, self::LOW_VALUE_FAMILY_TERMS)) {
            return null;
        }

        $category = Str::lower(Str::squish($fact['category']));
        $keyHead = Str::lower(Str::of($fact['key'])->before('.')->replace(['_', '-'], ' ')->squish()->toString());

        return $category.'|'.$keyHead;
    }

    /**
     * @param  array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference?: string
     * }  $fact
     * @param  array<string, int>  $familyCounts
     */
    private function engineeringRelevanceScore(array $fact, array $familyCounts): int
    {
        $searchText = $this->factSearchText($fact);
        $hasHighValueSignal = $this->searchTextContainsAny($searchText, self::HIGH_VALUE_TERMS)
            || $this->searchTextContainsAny($this->factValueSearchText($fact), self::BEHAVIORAL_VALUE_TERMS);
        $hasLowValueSignal = $this->searchTextContainsAny($searchText, self::LOW_VALUE_TERMS);
        $family = $this->lowValueFamilyKey($fact);
        $isDenseLowValueFamily = $family !== null && ($familyCounts[$family] ?? 0) >= 3;

        if ($this->hasCriticalBusinessRuleSignal($searchText)) {
            return 400;
        }

        if ($hasHighValueSignal) {
            return 300;
        }

        if ($hasLowValueSignal || $isDenseLowValueFamily) {
            return 10;
        }

        return 100;
    }

    private function hasCriticalBusinessRuleSignal(string $searchText): bool
    {
        if ($this->searchTextContainsAny($searchText, self::CRITICAL_VALUE_TERMS)) {
            return true;
        }

        return $this->searchTextContainsAny($searchText, self::RESERVATION_TERMS)
            && $this->searchTextContainsAny($searchText, self::RESERVATION_TIMEBOX_TERMS);
    }

    /**
     * @param  array{category: string, key: string, value: string, source_reference?: string}  $fact
     */
    private function factSearchText(array $fact): string
    {
        return $this->normalizeSearchText(
            $fact['category'].' '.$fact['key'].' '.$fact['value'].' '.$this->stripChunkPrefix((string) ($fact['source_reference'] ?? '')),
        );
    }

    private function applyWorkflowScopedKey(string $category, string $key, string $value, string $sourceReference): string
    {
        $evidence = $this->normalizeSearchText($category.' '.$key.' '.$value.' '.$sourceReference);
        $normalizedKey = Str::lower(Str::squish($key));

        if (! $this->isReservationFact($evidence, $normalizedKey)) {
            return $key;
        }

        $scope = $this->inferReservationWorkflowScope($evidence, $normalizedKey);

        if ($scope === null) {
            return $key;
        }

        return $this->buildScopedReservationKey(
            $normalizedKey,
            $scope,
            $this->inferReservationAttribute($evidence, $normalizedKey, $scope),
        );
    }

    private function isReservationFact(string $evidence, string $key): bool
    {
        return str_contains($key, 'reservation')
            || str_contains($key, 'reserva')
            || $this->searchTextContainsAny($evidence, self::RESERVATION_TERMS);
    }

    private function inferReservationWorkflowScope(string $evidence, string $key): ?string
    {
        if (str_contains($key, 'whatsapp') || $this->searchTextContainsAny($evidence, ['whatsapp'])) {
            return 'whatsapp';
        }

        if (str_contains($key, 'cart') || $this->searchTextContainsAny($evidence, ['cart', 'carrito', 'checkout'])) {
            return 'cart';
        }

        if (
            str_contains($key, 'conflict')
            || str_contains($key, 'priority')
            || $this->searchTextContainsAny($evidence, ['conflict', 'priority', 'prioridad'])
        ) {
            return 'conflict';
        }

        return null;
    }

    private function inferReservationAttribute(string $evidence, string $key, string $scope): ?string
    {
        if ($scope === 'conflict' || str_contains($key, 'priority') || $this->searchTextContainsAny($evidence, ['priority', 'prioridad'])) {
            return 'priority';
        }

        if ($scope === 'whatsapp' || str_contains($key, 'hold')) {
            return 'hold_duration';
        }

        if (str_contains($key, 'timeout')) {
            return 'timeout';
        }

        if (
            str_contains($key, 'expiration')
            || str_contains($key, 'release')
            || $this->searchTextContainsAny($evidence, ['release', 'released', 'expiration', 'expire', 'expires', 'expired', 'liberacion'])
        ) {
            return 'expiration_behavior';
        }

        if ($this->searchTextContainsAny($evidence, ['minute', 'minutes', 'minuto', 'minutos', 'timeout'])) {
            return 'timeout';
        }

        if ($this->searchTextContainsAny($evidence, ['week', 'weeks', 'semana', 'semanas', 'hold'])) {
            return 'hold_duration';
        }

        return null;
    }

    private function buildScopedReservationKey(string $key, string $scope, ?string $attribute): string
    {
        $parts = ['reservation', $scope];

        if ($attribute !== null) {
            $parts[] = $attribute;

            return implode('.', $parts);
        }

        $tail = $key;

        if (str_starts_with($tail, 'reservation.')) {
            $tail = Str::after($tail, 'reservation.');
        }

        foreach (['cart.', 'whatsapp.', 'conflict.'] as $prefix) {
            if (str_starts_with($tail, $prefix)) {
                $tail = Str::after($tail, $prefix);
                break;
            }
        }

        if ($tail !== '' && ! in_array($tail, ['reservation', $scope], true)) {
            $parts[] = $tail;
        }

        return implode('.', $parts);
    }

    /**
     * @param  array{value: string}  $fact
     */
    private function factValueSearchText(array $fact): string
    {
        return $this->normalizeSearchText($fact['value']);
    }

    private function normalizeSearchText(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replace(['.', '_', '-', '/', ':'], ' ')
            ->squish()
            ->toString();
    }

    /**
     * @param  list<string>  $terms
     */
    private function searchTextContainsAny(string $searchText, array $terms): bool
    {
        $padded = ' '.$searchText.' ';

        foreach ($terms as $term) {
            if (str_contains($padded, ' '.$term.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>  $facts
     * @return list<array{
     *     category: string,
     *     key: string,
     *     value: string,
     *     source_reference: string,
     *     confidence?: float|null,
     *     source_chunk_index?: int|null,
     *     content_source?: string
     * }>
     */
    private function capExtractedFacts(array $facts): array
    {
        $maxFacts = max(1, (int) config('services.project_facts.extraction_max_facts', 40));

        if (count($facts) <= $maxFacts) {
            return $facts;
        }

        return array_values(array_slice($facts, 0, $maxFacts));
    }

    private function readLocalDocumentText(KnowledgeDocument $document): ?string
    {
        $previewFormat = $this->contentService->resolvePreviewFormat($document);

        if ($previewFormat === null || $previewFormat === 'pdf') {
            return null;
        }

        if (! Storage::disk('local')->exists($document->disk_path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($document->disk_path);

        return is_string($contents) ? $contents : null;
    }
}
