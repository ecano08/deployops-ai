<?php

namespace App\Http\Resources;

use App\Enums\AiActionAuditEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiActionAuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $eventType = $this->event_type;

        return [
            'id' => $this->id,
            'event_type' => $eventType instanceof AiActionAuditEventType ? $eventType->value : (string) $eventType,
            'performed_by' => $this->performed_by,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
