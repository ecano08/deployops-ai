<?php

namespace App\Http\Resources;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeDocumentRevisionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KnowledgeDocument $document */
        $document = $this->resource;

        $status = $document->status;
        $lifecycleStatus = $document->lifecycle_status;
        $documentType = $document->document_type;

        return [
            'id' => $document->id,
            'title' => $document->title,
            'document_type' => $documentType instanceof KnowledgeDocumentType
                ? $documentType->value
                : (string) $documentType,
            'version_label' => $document->version_label,
            'revision_number' => $document->revision_number,
            'lifecycle_status' => $lifecycleStatus instanceof KnowledgeDocumentLifecycleStatus
                ? $lifecycleStatus->value
                : (string) $lifecycleStatus,
            'effective_at' => $document->effective_at,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'status' => $status instanceof KnowledgeDocumentStatus ? $status->value : (string) $status,
            'error_message' => $document->error_message,
            'chunk_count' => $document->chunk_count,
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
        ];
    }
}
