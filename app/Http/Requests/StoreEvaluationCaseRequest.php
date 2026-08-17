<?php

namespace App\Http\Requests;

use App\Models\EvaluationDataset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataset = $this->route('evaluation_dataset');

        return $dataset instanceof EvaluationDataset
            && ($this->user()?->can('update', $dataset) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'input' => ['required', 'string'],
            'expected_behavior' => ['required', 'string'],
            'expected_tools' => ['nullable', 'array'],
            'expected_tools.*' => ['string'],
            'expected_sources' => ['nullable', 'array'],
            'expected_sources.*' => ['string'],
        ];
    }
}
