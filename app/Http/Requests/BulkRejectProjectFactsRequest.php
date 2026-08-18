<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use App\Models\ProjectFact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkRejectProjectFactsRequest extends FormRequest
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
            'ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'min:1'],
            'source_document_id' => ['nullable', 'integer', 'min:1'],
            'source_revision' => ['required_with:source_document_id', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = $this->input('ids');
                $hasIds = is_array($ids) && $ids !== [];
                $hasSource = $this->filled('source_document_id');

                if ($hasIds === $hasSource) {
                    $validator->errors()->add(
                        $hasIds ? 'ids' : 'source_document_id',
                        'Provide either selected fact IDs or a source document revision, not both.',
                    );
                }
            },
        ];
    }
}
