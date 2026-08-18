<?php

namespace App\Jobs;

use App\Enums\ProjectFactExtractionStatus;
use App\Exceptions\CopilotException;
use App\Models\ProjectFactExtraction;
use App\Services\ProjectFactExtractionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractProjectFactsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 660;

    public function __construct(public ProjectFactExtraction $extraction) {}

    public function uniqueId(): string
    {
        return 'project-fact-extraction-'.$this->extraction->knowledge_document_id;
    }

    public function handle(ProjectFactExtractionService $extractionService): void
    {
        $extraction = $this->extraction->fresh();

        if ($extraction === null || $extraction->status?->isTerminal()) {
            return;
        }

        $extraction->markProcessing();

        try {
            $document = $extraction->knowledgeDocument;

            if ($document === null) {
                throw new CopilotException('Document content is unavailable for fact extraction.', 422);
            }

            $creator = $extraction->creator;

            if ($creator === null) {
                throw new CopilotException('Fact extraction failed. Please try again.', 422);
            }

            $facts = $extractionService->extractAndPropose($creator, $document);
            $extraction->fresh()?->markCompleted(count($facts));
        } catch (CopilotException $exception) {
            $this->failExtraction($extraction, $exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Project fact extraction job failed.', [
                'extraction_id' => $extraction->id,
                'document_id' => $extraction->knowledge_document_id,
                'workspace_id' => $extraction->workspace_id,
                'customer_id' => $extraction->customer_id,
                'deployment_id' => $extraction->deployment_id,
                'exception' => $exception::class,
            ]);

            $this->failExtraction($extraction, 'Fact extraction failed. Please try again.');

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $extraction = $this->extraction->fresh();

        if ($extraction === null || $extraction->status === ProjectFactExtractionStatus::Completed) {
            return;
        }

        if ($extraction->status === ProjectFactExtractionStatus::Failed) {
            return;
        }

        Log::warning('Project fact extraction job exhausted retries.', [
            'extraction_id' => $extraction->id,
            'document_id' => $extraction->knowledge_document_id,
            'exception' => $exception !== null ? $exception::class : null,
        ]);

        $extraction->markFailed('Fact extraction failed. Please try again.');
    }

    private function failExtraction(ProjectFactExtraction $extraction, string $message): void
    {
        $extraction->fresh()?->markFailed($message);
    }
}
