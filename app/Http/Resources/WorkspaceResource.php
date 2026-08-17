<?php

namespace App\Http\Resources;

use App\Enums\WorkspaceRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'owner_id' => $this->owner_id,
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'current_user_role' => $this->currentUserRole($request),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function currentUserRole(Request $request): ?string
    {
        if ($this->relationLoaded('pivot') && $this->pivot?->role) {
            $role = $this->pivot->role;

            return $role instanceof WorkspaceRole ? $role->value : (string) $role;
        }

        $user = $request->user();

        if (! $user) {
            return null;
        }

        return $this->roleFor($user)?->value;
    }
}
