<?php

namespace App\Models;

use App\Enums\EvaluationRunStatus;
use Database\Factories\EvaluationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'evaluation_dataset_id',
    'workspace_id',
    'customer_id',
    'deployment_id',
    'run_by',
    'status',
    'metrics',
    'started_at',
    'completed_at',
])]
class EvaluationRun extends Model
{
    /** @use HasFactory<EvaluationRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EvaluationRunStatus::class,
            'metrics' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EvaluationDataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'evaluation_dataset_id');
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
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /**
     * @return HasMany<EvaluationRunResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(EvaluationRunResult::class);
    }
}
