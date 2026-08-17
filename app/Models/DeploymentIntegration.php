<?php

namespace App\Models;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use Database\Factories\DeploymentIntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'deployment_id',
    'type',
    'name',
    'base_url',
    'endpoint',
    'status',
    'config',
    'secrets',
])]
class DeploymentIntegration extends Model
{
    /** @use HasFactory<DeploymentIntegrationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IntegrationType::class,
            'status' => IntegrationStatus::class,
            'config' => 'array',
            'secrets' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return HasMany<IntegrationActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(IntegrationActivity::class);
    }

    public function webhookSecret(): ?string
    {
        $secrets = $this->secrets;

        if (! is_array($secrets)) {
            return null;
        }

        $secret = $secrets['webhook_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function apiKey(): ?string
    {
        $secrets = $this->secrets;

        if (! is_array($secrets)) {
            return null;
        }

        $key = $secrets['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
