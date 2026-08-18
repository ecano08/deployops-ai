<?php

namespace App\Models;

use App\Enums\ProjectFactExtractionStatus;
use Database\Factories\ProjectFactExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'customer_id',
    'deployment_id',
    'knowledge_document_id',
    'source_revision',
    'created_by',
    'status',
    'proposed_count',
    'error_message',
    'started_at',
    'completed_at',
])]
class ProjectFactExtraction extends Model
{
    /** @use HasFactory<ProjectFactExtractionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'proposed_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectFactExtractionStatus::class,
            'source_revision' => 'integer',
            'proposed_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function knowledgeDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => ProjectFactExtractionStatus::Processing,
            'started_at' => $this->started_at ?? now(),
            'error_message' => null,
        ]);
    }

    public function markCompleted(int $proposedCount): void
    {
        $this->update([
            'status' => ProjectFactExtractionStatus::Completed,
            'proposed_count' => $proposedCount,
            'error_message' => null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => ProjectFactExtractionStatus::Failed,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  Builder<ProjectFactExtraction>  $query
     * @return Builder<ProjectFactExtraction>
     */
    public function scopeForDeployment(Builder $query, Deployment $deployment): Builder
    {
        return $query
            ->where('workspace_id', $deployment->workspace_id)
            ->where('customer_id', $deployment->customer_id)
            ->where('deployment_id', $deployment->id);
    }

    /**
     * @param  Builder<ProjectFactExtraction>  $query
     * @return Builder<ProjectFactExtraction>
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ProjectFactExtractionStatus::Pending,
            ProjectFactExtractionStatus::Processing,
        ]);
    }
}
