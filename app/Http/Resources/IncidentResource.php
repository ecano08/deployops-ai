<?php

namespace App\Http\Resources;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $severity = $this->severity;
        $status = $this->status;
        $source = $this->source;

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'deployment_id' => $this->deployment_id,
            'deployment_integration_id' => $this->deployment_integration_id,
            'created_by' => $this->created_by,
            'severity' => $severity instanceof IncidentSeverity ? $severity->value : (string) $severity,
            'status' => $status instanceof IncidentStatus ? $status->value : (string) $status,
            'source' => $source instanceof IncidentSource ? $source->value : (string) $source,
            'source_reference' => $this->source_reference,
            'title' => $this->title,
            'description' => $this->description,
            'root_cause' => $this->root_cause,
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
