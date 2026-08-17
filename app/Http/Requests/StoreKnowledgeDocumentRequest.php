<?php

namespace App\Http\Requests;

use App\Enums\KnowledgeDocumentType;
use App\Models\Deployment;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            && ($this->user()?->can('create', [KnowledgeDocument::class, $workspace]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('services.knowledge.max_file_size_kb', 10240);
        $deployment = $this->route('deployment');

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKilobytes,
                'mimes:pdf,txt,md',
                'mimetypes:application/pdf,text/plain,text/markdown,text/x-markdown',
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'document_type' => ['required', 'string', Rule::enum(KnowledgeDocumentType::class)],
            'version_label' => ['nullable', 'string', 'max:100'],
            'effective_at' => ['nullable', 'date'],
            'supersedes_document_id' => [
                'nullable',
                'integer',
                Rule::exists('knowledge_documents', 'id')
                    ->where(fn ($query) => $deployment instanceof Deployment
                        ? $query->where('deployment_id', $deployment->id)
                        : $query),
            ],
        ];
    }
}
