<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiToolCallTraceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool_name' => $this->tool_name,
            'duration_ms' => $this->duration_ms,
            'status' => $this->status,
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
