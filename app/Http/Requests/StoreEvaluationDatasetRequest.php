<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use App\Models\EvaluationDataset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        return $deployment instanceof Deployment
            && ($this->user()?->can('create', [EvaluationDataset::class, $deployment]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cases' => ['sometimes', 'array'],
            'cases.*.input' => ['required_with:cases', 'string'],
            'cases.*.expected_behavior' => ['required_with:cases', 'string'],
            'cases.*.expected_tools' => ['nullable', 'array'],
            'cases.*.expected_tools.*' => ['string'],
            'cases.*.expected_sources' => ['nullable', 'array'],
            'cases.*.expected_sources.*' => ['string'],
        ];
    }
}
