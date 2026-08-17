<?php

namespace App\Http\Resources;

use App\Enums\KnowledgeDocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeDocumentLibraryEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $entry */
        $entry = $this->resource;

        $documentType = $entry['document_type'];

        return [
            'chain_root_id' => $entry['chain_root_id'],
            'title' => $entry['title'],
            'document_type' => $documentType instanceof KnowledgeDocumentType
                ? $documentType->value
                : (string) $documentType,
            'revision_count' => $entry['revision_count'],
            'needs_attention' => $entry['needs_attention'],
            'attention_reason' => $entry['attention_reason'],
            'view_document_id' => $entry['view_document_id'],
            'updated_at' => $entry['updated_at'],
            'effective_at' => $entry['effective_at'],
            'active_revision' => $entry['active_revision'] !== null
                ? KnowledgeDocumentRevisionSummaryResource::make($entry['active_revision'])
                : null,
            'chain_head' => KnowledgeDocumentRevisionSummaryResource::make($entry['chain_head']),
            'attention_draft' => $entry['attention_draft'] !== null
                ? KnowledgeDocumentRevisionSummaryResource::make($entry['attention_draft'])
                : null,
        ];
    }
}
