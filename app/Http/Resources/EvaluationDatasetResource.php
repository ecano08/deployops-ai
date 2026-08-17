<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationDatasetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'deployment_id' => $this->deployment_id,
            'name' => $this->name,
            'description' => $this->description,
            'cases' => EvaluationCaseResource::collection($this->whenLoaded('cases')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
