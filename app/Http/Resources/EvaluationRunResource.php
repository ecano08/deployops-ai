<?php

namespace App\Http\Resources;

use App\Enums\EvaluationRunStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;

        return [
            'id' => $this->id,
            'evaluation_dataset_id' => $this->evaluation_dataset_id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'deployment_id' => $this->deployment_id,
            'run_by' => $this->run_by,
            'status' => $status instanceof EvaluationRunStatus ? $status->value : (string) $status,
            'metrics' => $this->metrics,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'results' => EvaluationRunResultResource::collection($this->whenLoaded('results')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
