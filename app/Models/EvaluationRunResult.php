<?php

namespace App\Models;

use Database\Factories\EvaluationRunResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'evaluation_run_id',
    'evaluation_case_id',
    'passed',
    'latency_ms',
    'tools_used',
    'sources_used',
    'answer',
    'error_message',
    'metrics',
])]
class EvaluationRunResult extends Model
{
    /** @use HasFactory<EvaluationRunResultFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'tools_used' => 'array',
            'sources_used' => 'array',
            'metrics' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EvaluationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'evaluation_run_id');
    }

    /**
     * @return BelongsTo<EvaluationCase, $this>
     */
    public function evaluationCase(): BelongsTo
    {
        return $this->belongsTo(EvaluationCase::class);
    }
}
