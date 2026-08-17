<?php

namespace App\Http\Resources;

use App\Services\CopilotQuestionRedactor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiTraceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $redactor = app(CopilotQuestionRedactor::class);

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'deployment_id' => $this->deployment_id,
            'user_id' => $this->user_id,
            'model' => $this->model,
            'question_preview' => $redactor->redact((string) $this->question),
            'tool_names' => $this->tool_names ?? [],
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'rag_used' => $this->rag_used,
            'rag_result_count' => $this->rag_result_count,
            'estimated_cost_usd' => $this->estimated_cost_usd,
            'latency_ms' => $this->latency_ms,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'tool_call_traces' => AiToolCallTraceResource::collection($this->whenLoaded('toolCallTraces')),
            'created_at' => $this->created_at,
        ];
    }
}
