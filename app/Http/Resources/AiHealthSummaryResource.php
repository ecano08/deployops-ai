<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiHealthSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'request_count' => $this->resource['request_count'],
            'failure_count' => $this->resource['failure_count'],
            'failure_rate' => $this->resource['failure_rate'],
            'average_latency_ms' => $this->resource['average_latency_ms'],
            'total_input_tokens' => $this->resource['total_input_tokens'],
            'total_output_tokens' => $this->resource['total_output_tokens'],
            'estimated_cost_usd' => $this->resource['estimated_cost_usd'],
            'tool_failure_count' => $this->resource['tool_failure_count'],
            'rag_request_count' => $this->resource['rag_request_count'],
        ];
    }
}
