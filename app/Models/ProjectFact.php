<?php

namespace App\Models;

use App\Enums\ProjectFactStatus;
use Database\Factories\ProjectFactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'workspace_id',
    'customer_id',
    'deployment_id',
    'category',
    'key',
    'value',
    'source_document_id',
    'source_revision',
    'source_reference',
    'confidence',
    'status',
    'verified_at',
    'verified_by',
    'superseded_by_id',
    'created_by',
    'extraction_metadata',
])]
class ProjectFact extends Model
{
    /** @use HasFactory<ProjectFactFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectFactStatus::class,
            'confidence' => 'float',
            'source_revision' => 'integer',
            'verified_at' => 'datetime',
            'extraction_metadata' => 'array',
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
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'source_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<ProjectFact, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(ProjectFact::class, 'superseded_by_id');
    }

    public function isEditable(): bool
    {
        return $this->status === ProjectFactStatus::Proposed;
    }

    public static function equivalentFingerprint(string $category, string $key, string $value): string
    {
        return Str::lower(Str::squish($category)).'|'.
            Str::lower(Str::squish($key)).'|'.
            Str::lower(Str::squish(rtrim($value, " \t.;,")));
    }

    /**
     * @param  Builder<ProjectFact>  $query
     * @return Builder<ProjectFact>
     */
    public function scopeForDeployment(Builder $query, Deployment $deployment): Builder
    {
        return $query
            ->where('workspace_id', $deployment->workspace_id)
            ->where('customer_id', $deployment->customer_id)
            ->where('deployment_id', $deployment->id);
    }

    /**
     * @param  Builder<ProjectFact>  $query
     * @return Builder<ProjectFact>
     */
    public function scopeWithStatus(Builder $query, ?ProjectFactStatus $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        return $query->where('status', $status);
    }
}
