<?php

namespace App\Http\Requests;

use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveKnowledgeDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $document = $this->route('knowledge_document');

        return $document instanceof KnowledgeDocument
            && ($this->user()?->can('archive', $document) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
