<?php

namespace App\Http\Resources;

use App\Enums\DeploymentStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeploymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stage = $this->stage;

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'customer_id' => $this->customer_id,
            'name' => $this->name,
            'description' => $this->description,
            'stage' => $stage instanceof DeploymentStage ? $stage->value : (string) $stage,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
