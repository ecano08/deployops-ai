<?php

namespace App\Models;

use Database\Factories\EvaluationDatasetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'customer_id', 'deployment_id', 'name', 'description'])]
class EvaluationDataset extends Model
{
    /** @use HasFactory<EvaluationDatasetFactory> */
    use HasFactory;

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
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return HasMany<EvaluationCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(EvaluationCase::class);
    }

    /**
     * @return HasMany<EvaluationCase, $this>
     */
    public function evaluationCases(): HasMany
    {
        return $this->cases();
    }

    /**
     * @return HasMany<EvaluationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(EvaluationRun::class);
    }
}
