<?php

namespace App\Services;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\CopilotRequestLog;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\Incident;
use App\Models\User;

class IncidentService
{
    public function createFromAiFailure(CopilotRequestLog $trace, string $errorMessage): Incident
    {
        $existing = Incident::query()
            ->where('deployment_id', $trace->deployment_id)
            ->where('workspace_id', $trace->workspace_id)
            ->where('source', IncidentSource::AiFailure)
            ->where('source_reference', (string) $trace->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Incident::query()->create([
            'workspace_id' => $trace->workspace_id,
            'customer_id' => $trace->customer_id,
            'deployment_id' => $trace->deployment_id,
            'severity' => IncidentSeverity::High,
            'status' => IncidentStatus::Open,
            'source' => IncidentSource::AiFailure,
            'source_reference' => (string) $trace->id,
            'title' => 'Copilot request failed',
            'description' => $this->sanitizeText($errorMessage),
        ]);
    }

    public function createFromIntegrationFailure(
        DeploymentIntegration $integration,
        string $message,
        ?array $metadata = null,
    ): Incident {
        $reference = sprintf('integration:%d:%s', $integration->id, md5($message));

        $existing = Incident::query()
            ->where('deployment_id', $integration->deployment_id)
            ->where('workspace_id', $integration->workspace_id)
            ->where('source', IncidentSource::IntegrationFailure)
            ->where('source_reference', $reference)
            ->where('status', '!=', IncidentStatus::Resolved->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $description = $this->sanitizeText($message);

        if ($metadata !== null) {
            $safeMetadata = $this->safeMetadataSummary($metadata);

            if ($safeMetadata !== '') {
                $description .= ' '.$safeMetadata;
            }
        }

        return Incident::query()->create([
            'workspace_id' => $integration->workspace_id,
            'customer_id' => $integration->deployment->customer_id,
            'deployment_id' => $integration->deployment_id,
            'deployment_integration_id' => $integration->id,
            'severity' => IncidentSeverity::Medium,
            'status' => IncidentStatus::Open,
            'source' => IncidentSource::IntegrationFailure,
            'source_reference' => $reference,
            'title' => sprintf('Integration failure: %s', $integration->name),
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createManual(Deployment $deployment, User $creator, array $data): Incident
    {
        return Incident::query()->create([
            'workspace_id' => $deployment->workspace_id,
            'customer_id' => $deployment->customer_id,
            'deployment_id' => $deployment->id,
            'created_by' => $creator->id,
            'severity' => IncidentSeverity::from($data['severity']),
            'status' => IncidentStatus::Open,
            'source' => IncidentSource::Manual,
            'title' => $data['title'],
            'description' => $data['description'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Incident $incident, array $data): Incident
    {
        $status = isset($data['status']) ? IncidentStatus::from($data['status']) : null;
        $severity = isset($data['severity']) ? IncidentSeverity::from($data['severity']) : null;

        $incident->fill(array_filter([
            'status' => $status,
            'severity' => $severity,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'root_cause' => $data['root_cause'] ?? null,
            'resolution' => $data['resolution'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        if ($status === IncidentStatus::Resolved && $incident->resolved_at === null) {
            $incident->resolved_at = now();
        }

        if ($status !== null && $status !== IncidentStatus::Resolved) {
            $incident->resolved_at = null;
        }

        $incident->save();

        return $incident->refresh();
    }

    private function sanitizeText(string $text): string
    {
        $redacted = preg_replace('/\bsk-[a-zA-Z0-9]{8,}/', '[REDACTED]', $text) ?? $text;
        $redacted = preg_replace('/\bwhsec_[a-zA-Z0-9]+/', '[REDACTED]', $redacted) ?? $redacted;
        $redacted = preg_replace('/(?i)(api[_-]?key|token|password|secret|authorization)\s*[:=]\s*\S+/', '[REDACTED]', $redacted) ?? $redacted;

        return mb_substr($redacted, 0, 2000);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function safeMetadataSummary(array $metadata): string
    {
        $parts = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || preg_match('/(secret|password|token|api_key)/i', $key) === 1) {
                continue;
            }

            if (is_scalar($value)) {
                $parts[] = sprintf('%s=%s', $key, $value);
            }
        }

        return $parts === [] ? '' : '('.implode(', ', $parts).')';
    }
}
