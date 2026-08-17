<?php

namespace App\Models;

use App\Enums\AiActionAuditEventType;
use Database\Factories\AiActionAuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_proposed_action_id', 'event_type', 'performed_by', 'metadata'])]
class AiActionAuditEvent extends Model
{
    /** @use HasFactory<AiActionAuditEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => AiActionAuditEventType::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiProposedAction, $this>
     */
    public function proposedAction(): BelongsTo
    {
        return $this->belongsTo(AiProposedAction::class, 'ai_proposed_action_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
