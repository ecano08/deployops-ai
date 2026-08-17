<?php

namespace App\Models;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'workspace_id',
    'customer_id',
    'deployment_id',
    'uploaded_by',
    'title',
    'document_type',
    'version_label',
    'revision_number',
    'content_hash',
    'lifecycle_status',
    'effective_at',
    'supersedes_document_id',
    'chain_root_id',
    'metadata',
    'original_filename',
    'mime_type',
    'disk_path',
    'size_bytes',
    'status',
    'error_message',
    'chunk_count',
])]
class KnowledgeDocument extends Model
{
    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => KnowledgeDocumentType::class,
            'lifecycle_status' => KnowledgeDocumentLifecycleStatus::class,
            'status' => KnowledgeDocumentStatus::class,
            'effective_at' => 'datetime',
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'chunk_count' => 'integer',
            'revision_number' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'supersedes_document_id');
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function chainRoot(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'chain_root_id');
    }

    /**
     * @return HasMany<KnowledgeDocument, $this>
     */
    public function supersededBy(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'supersedes_document_id');
    }

    public function isAuthoritativeForRag(): bool
    {
        return $this->lifecycle_status === KnowledgeDocumentLifecycleStatus::Active
            && $this->status === KnowledgeDocumentStatus::Ready;
    }

    /**
     * @param  Builder<KnowledgeDocument>  $query
     * @return Builder<KnowledgeDocument>
     */
    public function scopeAuthoritativeForRag(Builder $query): Builder
    {
        return $query
            ->where('lifecycle_status', KnowledgeDocumentLifecycleStatus::Active)
            ->where('status', KnowledgeDocumentStatus::Ready);
    }

    /**
     * @return list<int>
     */
    public function versionFamilyIds(): array
    {
        $familyIds = collect([$this->id]);

        $backward = $this->collectBackwardVersionIds();
        $forward = $this->collectForwardVersionIds($backward->merge([$this->id])->all());

        return $familyIds
            ->merge($backward)
            ->merge($forward)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, int>
     */
    private function collectBackwardVersionIds(): Collection
    {
        $ids = collect();
        $currentId = $this->supersedes_document_id;

        while ($currentId !== null) {
            $ids->push($currentId);

            $currentId = KnowledgeDocument::query()
                ->whereKey($currentId)
                ->value('supersedes_document_id');
        }

        return $ids;
    }

    /**
     * @param  list<int>  $seedIds
     * @return Collection<int, int>
     */
    private function collectForwardVersionIds(array $seedIds): Collection
    {
        $ids = collect();
        $queue = $seedIds;

        while ($queue !== []) {
            $children = KnowledgeDocument::query()
                ->whereIn('supersedes_document_id', $queue)
                ->where('deployment_id', $this->deployment_id)
                ->pluck('id')
                ->all();

            $newChildren = array_values(array_diff($children, $ids->all(), $seedIds));

            if ($newChildren === []) {
                break;
            }

            $ids = $ids->merge($newChildren);
            $queue = $newChildren;
        }

        return $ids;
    }
}
