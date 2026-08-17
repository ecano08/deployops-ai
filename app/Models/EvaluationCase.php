<?php

namespace App\Models;

use Database\Factories\EvaluationCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['evaluation_dataset_id', 'input', 'expected_behavior', 'expected_tools', 'expected_sources'])]
class EvaluationCase extends Model
{
    /** @use HasFactory<EvaluationCaseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_tools' => 'array',
            'expected_sources' => 'array',
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
     * @return HasMany<EvaluationRunResult, $this>
     */
    public function runResults(): HasMany
    {
        return $this->hasMany(EvaluationRunResult::class);
    }
}
