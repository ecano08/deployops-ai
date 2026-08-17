<?php

namespace App\Services;

use App\Models\DeploymentIntegration;
use Illuminate\Support\Facades\Cache;

class WebhookReplayGuard
{
    public function isReplay(DeploymentIntegration $integration, string $signatureHeader): bool
    {
        return Cache::has($this->cacheKey($integration, $signatureHeader));
    }

    public function remember(DeploymentIntegration $integration, string $signatureHeader, int $ttlSeconds): void
    {
        Cache::put(
            $this->cacheKey($integration, $signatureHeader),
            true,
            now()->addSeconds($ttlSeconds),
        );
    }

    private function cacheKey(DeploymentIntegration $integration, string $signatureHeader): string
    {
        return 'integration_webhook_replay:'.$integration->id.':'.hash('sha256', $signatureHeader);
    }
}
