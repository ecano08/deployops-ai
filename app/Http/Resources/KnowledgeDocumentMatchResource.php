<?php

namespace App\Http\Resources;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KnowledgeDocument */
class KnowledgeDocumentMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['document']->id,
            'title' => $this->resource['document']->title,
            'revision_number' => $this->resource['document']->revision_number,
            'lifecycle_status' => $this->resource['document']->lifecycle_status->value,
            'original_filename' => $this->resource['document']->original_filename,
            'chain_head_id' => $this->resource['chain_head']->id,
            'chain_head_revision_number' => $this->resource['chain_head']->revision_number,
        ];
    }
}
