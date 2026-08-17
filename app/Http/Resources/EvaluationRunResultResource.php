<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationRunResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_case_id' => $this->evaluation_case_id,
            'passed' => $this->passed,
            'latency_ms' => $this->latency_ms,
            'tools_used' => $this->tools_used,
            'sources_used' => $this->sources_used,
            'answer' => $this->answer,
            'error_message' => $this->error_message,
            'metrics' => $this->metrics,
            'evaluation_case' => EvaluationCaseResource::make($this->whenLoaded('evaluationCase')),
        ];
    }
}
