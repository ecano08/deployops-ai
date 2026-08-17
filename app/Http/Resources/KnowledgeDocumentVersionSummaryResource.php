<?php

namespace App\Http\Resources;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeDocumentVersionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $lifecycleStatus = $this->lifecycle_status;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'revision_number' => $this->revision_number,
            'lifecycle_status' => $lifecycleStatus instanceof KnowledgeDocumentLifecycleStatus
                ? $lifecycleStatus->value
                : (string) $lifecycleStatus,
            'status' => $status instanceof KnowledgeDocumentStatus ? $status->value : (string) $status,
            'version_label' => $this->version_label,
            'effective_at' => $this->effective_at,
            'supersedes_document_id' => $this->supersedes_document_id,
            'created_at' => $this->created_at,
        ];
    }
}
