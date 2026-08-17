<?php

namespace App\Http\Resources;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceInvitationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->role;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $role instanceof WorkspaceRole ? $role->value : $role,
            'status' => $this->status?->value ?? $this->status,
            'expires_at' => $this->expires_at,
            'invitation_url' => $this->when(
                $this->shouldExposeInvitationUrl($request),
                fn (): string => $this->resource->invitationUrl(),
            ),
            'workspace' => $this->whenLoaded('workspace', fn (): array => [
                'name' => $this->workspace->name,
            ]),
        ];
    }

    private function shouldExposeInvitationUrl(Request $request): bool
    {
        $user = $request->user();
        $workspace = $this->relationLoaded('workspace')
            ? $this->workspace
            : $request->route('workspace');

        return $user !== null
            && $workspace instanceof Workspace
            && $user->can('manageMembers', $workspace);
    }
}
