<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use App\Models\ProjectFact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkVerifyProjectFactsRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }
}
