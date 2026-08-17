<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiActionRequesterResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private int $requesterId,
        private ?Workspace $workspace = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requester = $this->resource instanceof User ? $this->resource : null;

        if ($requester === null || ! $this->requesterBelongsToWorkspace($requester)) {
            return [
                'id' => $this->requesterId,
                'name' => null,
            ];
        }

        return [
            'id' => $this->requesterId,
            'name' => $requester->name,
            'email' => $requester->email,
        ];
    }

    private function requesterBelongsToWorkspace(User $requester): bool
    {
        if ($this->workspace === null) {
            return false;
        }

        return $this->workspace->includesUser($requester);
    }
}
