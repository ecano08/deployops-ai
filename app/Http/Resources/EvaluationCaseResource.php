<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_dataset_id' => $this->evaluation_dataset_id,
            'input' => $this->input,
            'expected_behavior' => $this->expected_behavior,
            'expected_tools' => $this->expected_tools,
            'expected_sources' => $this->expected_sources,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
