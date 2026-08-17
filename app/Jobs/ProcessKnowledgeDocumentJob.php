<?php

namespace App\Jobs;

use App\Enums\KnowledgeDocumentStatus;
use App\Models\KnowledgeDocument;
use App\Services\AiServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public KnowledgeDocument $document) {}

    public function handle(AiServiceClient $aiService): void
    {
        $document = $this->document->fresh();

        if ($document === null) {
            return;
        }

        $document->update([
            'status' => KnowledgeDocumentStatus::Processing,
            'error_message' => null,
        ]);

        try {
            $contents = Storage::disk('local')->get($document->disk_path);

            if ($contents === null) {
                throw new \RuntimeException('Stored document file is missing.');
            }

            $result = $aiService->processDocument($document, $contents);

            $document->update([
                'status' => KnowledgeDocumentStatus::Ready,
                'chunk_count' => (int) ($result['chunk_count'] ?? 0),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Knowledge document processing failed.', [
                'document_id' => $document->id,
                'message' => $exception->getMessage(),
            ]);

            $document->update([
                'status' => KnowledgeDocumentStatus::Failed,
                'error_message' => 'Document processing failed.',
            ]);
        }
    }
}
