<?php

namespace App\Http\Resources;

use App\Enums\ProjectFactStatus;
use App\Models\ProjectFact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectFact $fact */
        $fact = $this->resource;
        $status = $fact->status;

        return [
            'id' => $fact->id,
            'workspace_id' => $fact->workspace_id,
            'customer_id' => $fact->customer_id,
            'deployment_id' => $fact->deployment_id,
            'category' => $fact->category,
            'key' => $fact->key,
            'value' => $fact->value,
            'source_document_id' => $fact->source_document_id,
            'source_revision' => $fact->source_revision,
            'source_reference' => $fact->source_reference,
            'confidence' => $fact->confidence,
            'status' => $status instanceof ProjectFactStatus ? $status->value : (string) $status,
            'verified_at' => $fact->verified_at,
            'verified_by' => $this->whenLoaded('verifier', fn () => $fact->verifier ? [
                'id' => $fact->verifier->id,
                'name' => $fact->verifier->name,
                'email' => $fact->verifier->email,
            ] : null),
            'superseded_by_id' => $fact->superseded_by_id,
            'created_by' => $this->whenLoaded('creator', fn () => $fact->creator ? [
                'id' => $fact->creator->id,
                'name' => $fact->creator->name,
                'email' => $fact->creator->email,
            ] : null),
            'source_document' => $this->whenLoaded('sourceDocument', function () use ($fact) {
                if ($fact->sourceDocument === null) {
                    return null;
                }

                return [
                    'id' => $fact->sourceDocument->id,
                    'title' => $fact->sourceDocument->title,
                    'revision_number' => $fact->sourceDocument->revision_number,
                    'original_filename' => $fact->sourceDocument->original_filename,
                ];
            }),
            'created_at' => $fact->created_at,
            'updated_at' => $fact->updated_at,
        ];
    }
}
