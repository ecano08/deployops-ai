<?php

namespace App\Models;

use App\Enums\DeploymentStage;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'customer_id', 'name', 'description', 'stage'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DeploymentStage::class,
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<DeploymentIntegration, $this>
     */
    public function integrations(): HasMany
    {
        return $this->hasMany(DeploymentIntegration::class);
    }

    /**
     * Scoped route binding alias for nested integration routes.
     *
     * @return HasMany<DeploymentIntegration, $this>
     */
    public function deploymentIntegrations(): HasMany
    {
        return $this->integrations();
    }

    /**
     * @return HasMany<KnowledgeDocument, $this>
     */
    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    /**
     * @return HasMany<EvaluationDataset, $this>
     */
    public function evaluationDatasets(): HasMany
    {
        return $this->hasMany(EvaluationDataset::class);
    }

    /**
     * @return HasMany<AiProposedAction, $this>
     */
    public function aiProposedActions(): HasMany
    {
        return $this->hasMany(AiProposedAction::class);
    }

    /**
     * @return HasMany<CopilotRequestLog, $this>
     */
    public function copilotRequestLogs(): HasMany
    {
        return $this->hasMany(CopilotRequestLog::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
