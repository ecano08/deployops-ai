<?php

namespace App\Services;

use App\Enums\KnowledgeDocumentStatus;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeDocumentContentService
{
    public function resolvePreviewFormat(KnowledgeDocument $document): ?string
    {
        $extension = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'pdf',
            'txt' => 'text',
            'md' => 'markdown',
            default => match ($document->mime_type) {
                'application/pdf' => 'pdf',
                'text/plain' => 'text',
                'text/markdown', 'text/x-markdown' => 'markdown',
                default => null,
            },
        };
    }

    /**
     * @return Collection<int, KnowledgeDocument>
     */
    public function versionHistory(KnowledgeDocument $document): Collection
    {
        $familyIds = $document->versionFamilyIds();

        return KnowledgeDocument::query()
            ->whereIn('id', $familyIds)
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->get();
    }

    public function buildContentResponse(KnowledgeDocument $document): StreamedResponse|JsonResponse
    {
        if ($document->status !== KnowledgeDocumentStatus::Ready) {
            return response()->json([
                'message' => $this->notReadyMessage($document),
                'preview_state' => $document->status->value,
                'error_message' => $document->error_message,
            ], 422);
        }

        $previewFormat = $this->resolvePreviewFormat($document);

        if ($previewFormat === null) {
            return response()->json([
                'message' => 'Preview is not supported for this file type.',
                'preview_state' => 'unsupported',
            ], 422);
        }

        if (! Storage::disk('local')->exists($document->disk_path)) {
            return response()->json([
                'message' => 'Document content is unavailable.',
                'preview_state' => 'unavailable',
            ], 422);
        }

        $contentType = match ($previewFormat) {
            'pdf' => 'application/pdf',
            'markdown' => 'text/markdown; charset=UTF-8',
            default => 'text/plain; charset=UTF-8',
        };

        $filename = addcslashes($document->original_filename, '"\\');

        return Storage::disk('local')->response(
            $document->disk_path,
            $document->original_filename,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function notReadyMessage(KnowledgeDocument $document): string
    {
        return match ($document->status) {
            KnowledgeDocumentStatus::Pending => 'Document is queued for processing and cannot be previewed yet.',
            KnowledgeDocumentStatus::Processing => 'Document is still processing and cannot be previewed yet.',
            KnowledgeDocumentStatus::Failed => 'Document processing failed, so preview is unavailable.',
            default => 'Document is not ready for preview.',
        };
    }
}
