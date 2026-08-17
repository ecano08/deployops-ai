<?php

namespace App\Http\Resources;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Support\ResourceSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeploymentIntegrationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type;
        $status = $this->status;

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'deployment_id' => $this->deployment_id,
            'type' => $type instanceof IntegrationType ? $type->value : (string) $type,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'endpoint' => $this->endpoint,
            'status' => $status instanceof IntegrationStatus ? $status->value : (string) $status,
            'config' => ResourceSanitizer::integrationConfig($this->config),
            'has_api_key' => $this->apiKey() !== null,
            'has_webhook_secret' => $this->webhookSecret() !== null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
