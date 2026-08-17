<?php

namespace App\Models;

use Database\Factories\AiToolCallTraceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'copilot_request_log_id',
    'workspace_id',
    'deployment_id',
    'tool_name',
    'duration_ms',
    'status',
    'metadata',
])]
class AiToolCallTrace extends Model
{
    /** @use HasFactory<AiToolCallTraceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CopilotRequestLog, $this>
     */
    public function copilotRequestLog(): BelongsTo
    {
        return $this->belongsTo(CopilotRequestLog::class);
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
}
