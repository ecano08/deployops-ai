<?php

namespace App\Http\Resources;

use App\Enums\ProjectFactExtractionStatus;
use App\Models\ProjectFactExtraction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFactExtractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectFactExtraction $extraction */
        $extraction = $this->resource;
        $status = $extraction->status;

        return [
            'id' => $extraction->id,
            'workspace_id' => $extraction->workspace_id,
            'customer_id' => $extraction->customer_id,
            'deployment_id' => $extraction->deployment_id,
            'source_document_id' => $extraction->knowledge_document_id,
            'source_revision' => $extraction->source_revision,
            'status' => $status instanceof ProjectFactExtractionStatus ? $status->value : (string) $status,
            'proposed_count' => $extraction->proposed_count,
            'error_message' => $extraction->error_message,
            'started_at' => $extraction->started_at,
            'completed_at' => $extraction->completed_at,
            'created_at' => $extraction->created_at,
            'updated_at' => $extraction->updated_at,
        ];
    }
}
