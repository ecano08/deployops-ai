<?php

namespace App\Http\Resources;

use App\Services\GroundedContextPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroundedContextResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GroundedContextPackage $package */
        $package = $this->resource;

        return $package->toArray();
    }
}
