<?php

namespace App\Models;

use App\Enums\IntegrationActivityType;
use Database\Factories\IntegrationActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'deployment_integration_id',
    'type',
    'status',
    'metadata',
    'message',
])]
class IntegrationActivity extends Model
{
    /** @use HasFactory<IntegrationActivityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IntegrationActivityType::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DeploymentIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(DeploymentIntegration::class, 'deployment_integration_id');
    }
}
