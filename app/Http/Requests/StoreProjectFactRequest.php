<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use App\Models\ProjectFact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        return $deployment instanceof Deployment
            && ($this->user()?->can('create', [ProjectFact::class, $deployment]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:5000'],
            'source_document_id' => ['nullable', 'integer', 'exists:knowledge_documents,id'],
            'source_reference' => ['nullable', 'string', 'max:5000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
