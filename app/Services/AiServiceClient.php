<?php

namespace App\Services;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class AiServiceClient
{
    /**
     * @return array{chunk_count: int}
     */
    public function processDocument(KnowledgeDocument $document, string $contents): array
    {
        $response = $this->client()
            ->post('/documents/process', [
                'workspace_id' => $document->workspace_id,
                'customer_id' => $document->customer_id,
                'deployment_id' => $document->deployment_id,
                'document_id' => $document->id,
                'filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'content_base64' => base64_encode($contents),
            ])
            ->throw();

        /** @var array{chunk_count?: int} $payload */
        $payload = $response->json();

        return [
            'chunk_count' => (int) ($payload['chunk_count'] ?? 0),
        ];
    }

    /**
     * @return array<int, array{
     *     document_id: int,
     *     source_filename: string,
     *     chunk_index: int,
     *     content: string,
     *     score: float
     * }>
     */
    public function searchKnowledge(
        int $workspaceId,
        int $customerId,
        int $deploymentId,
        string $query,
        int $topK,
    ): array {
        $response = $this->client()
            ->post('/search', [
                'workspace_id' => $workspaceId,
                'customer_id' => $customerId,
                'deployment_id' => $deploymentId,
                'query' => $query,
                'top_k' => $topK,
            ])
            ->throw();

        /** @var array{results?: array<int, array<string, mixed>>} $payload */
        $payload = $response->json();
        $results = $payload['results'] ?? [];

        return array_values(array_map(
            static fn (array $result): array => [
                'document_id' => (int) $result['document_id'],
                'source_filename' => (string) $result['source_filename'],
                'chunk_index' => (int) $result['chunk_index'],
                'content' => (string) $result['content'],
                'score' => (float) $result['score'],
            ],
            $results,
        ));
    }

    public function deleteDocumentVectors(KnowledgeDocument $document): void
    {
        try {
            $this->client()
                ->post('/documents/delete', [
                    'workspace_id' => $document->workspace_id,
                    'customer_id' => $document->customer_id,
                    'deployment_id' => $document->deployment_id,
                    'document_id' => $document->id,
                ])
                ->throw();
        } catch (ConnectionException|RequestException) {
            // Vector cleanup is best-effort when the AI service is unavailable.
        }
    }

    private function client(): PendingRequest
    {
        $timeout = (int) config('services.ai_service.timeout', 60);
        $token = (string) config('services.ai_service.token', '');

        $request = Http::baseUrl(rtrim((string) config('services.ai_service.url'), '/'))
            ->timeout($timeout)
            ->connectTimeout((int) config('services.ai_service.connect_timeout', 5))
            ->acceptJson();

        if ($token !== '') {
            $request = $request->withHeaders([
                'X-AI-Service-Token' => $token,
            ]);
        }

        return $request;
    }
}
