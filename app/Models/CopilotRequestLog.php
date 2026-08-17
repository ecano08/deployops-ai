<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'user_id',
    'customer_id',
    'deployment_id',
    'model',
    'question',
    'tool_names',
    'input_tokens',
    'output_tokens',
    'rag_used',
    'rag_result_count',
    'estimated_cost_usd',
    'latency_ms',
    'status',
    'error_message',
])]
class CopilotRequestLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool_names' => 'array',
            'rag_used' => 'boolean',
            'estimated_cost_usd' => 'decimal:6',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return HasMany<AiToolCallTrace, $this>
     */
    public function toolCallTraces(): HasMany
    {
        return $this->hasMany(AiToolCallTrace::class);
    }
}
