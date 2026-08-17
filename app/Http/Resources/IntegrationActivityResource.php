<?php

namespace App\Http\Resources;

use App\Enums\IntegrationActivityType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationActivityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type;

        return [
            'id' => $this->id,
            'deployment_integration_id' => $this->deployment_integration_id,
            'type' => $type instanceof IntegrationActivityType ? $type->value : (string) $type,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'message' => $this->message,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
