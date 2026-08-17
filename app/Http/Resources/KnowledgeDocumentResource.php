<?php

namespace App\Http\Resources;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeDocumentContentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class KnowledgeDocumentResource extends JsonResource
{
    /** @var Collection<int, KnowledgeDocument>|null */
    protected ?Collection $versionHistory = null;

    /**
     * @param  Collection<int, KnowledgeDocument>  $versionHistory
     */
    public function withVersionHistory(Collection $versionHistory): self
    {
        $this->versionHistory = $versionHistory;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $lifecycleStatus = $this->lifecycle_status;
        $documentType = $this->document_type;

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'deployment_id' => $this->deployment_id,
            'title' => $this->title,
            'document_type' => $documentType instanceof KnowledgeDocumentType
                ? $documentType->value
                : (string) $documentType,
            'version_label' => $this->version_label,
            'revision_number' => $this->revision_number,
            'lifecycle_status' => $lifecycleStatus instanceof KnowledgeDocumentLifecycleStatus
                ? $lifecycleStatus->value
                : (string) $lifecycleStatus,
            'effective_at' => $this->effective_at,
            'supersedes_document_id' => $this->supersedes_document_id,
            'supersedes' => $this->whenLoaded('supersedes', function () {
                if ($this->supersedes === null) {
                    return null;
                }

                return [
                    'id' => $this->supersedes->id,
                    'title' => $this->supersedes->title,
                    'revision_number' => $this->supersedes->revision_number,
                ];
            }),
            'metadata' => $this->metadata,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'status' => $status instanceof KnowledgeDocumentStatus ? $status->value : (string) $status,
            'error_message' => $this->error_message,
            'chunk_count' => $this->chunk_count,
            'uploaded_by' => $this->uploaded_by,
            'preview_format' => app(KnowledgeDocumentContentService::class)->resolvePreviewFormat($this->resource),
            'version_history' => $this->when(
                $this->versionHistory !== null,
                fn () => KnowledgeDocumentVersionSummaryResource::collection($this->versionHistory),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
