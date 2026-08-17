<?php

namespace App\Http\Requests;

use App\Enums\KnowledgeDocumentLifecycleStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexKnowledgeDocumentsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            && ($this->user()?->can('viewAny', [KnowledgeDocument::class, $workspace]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'view' => ['nullable', 'string', Rule::in(['current', 'needs_attention', 'archived'])],
            'search' => ['nullable', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', Rule::enum(KnowledgeDocumentType::class)],
            'lifecycle_status' => ['nullable', 'string', Rule::enum(KnowledgeDocumentLifecycleStatus::class)],
            'attention' => ['nullable', 'string', Rule::in(['needs_attention', 'processing_failed', 'draft_pending'])],
            'status' => ['nullable', 'string', Rule::enum(KnowledgeDocumentStatus::class)],
            'sort' => ['nullable', 'string', Rule::in(['updated_at', 'title', 'effective_at'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
