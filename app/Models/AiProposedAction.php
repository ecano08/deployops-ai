<?php

namespace App\Models;

use App\Enums\AiActionStatus;
use App\Enums\AiActionType;
use Database\Factories\AiProposedActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'workspace_id',
    'customer_id',
    'deployment_id',
    'action_type',
    'payload',
    'status',
    'requested_by',
    'approved_by',
    'executed_at',
    'error_message',
])]
class AiProposedAction extends Model
{
    /** @use HasFactory<AiProposedActionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => AiActionType::class,
            'payload' => 'array',
            'status' => AiActionStatus::class,
            'executed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AiProposedAction $action): void {
            if ($action->isDirty(['action_type', 'payload'])) {
                throw ValidationException::withMessages([
                    'action' => 'Proposed action details cannot be changed after creation.',
                ]);
            }
        });
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<AiActionAuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AiActionAuditEvent::class);
    }
}
