<?php

namespace App\Http\Requests;

use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKilobytes,
                'mimes:pdf,txt,md',
                'mimetypes:application/pdf,text/plain,text/markdown,text/x-markdown',
            ],
        ];
    }
}
