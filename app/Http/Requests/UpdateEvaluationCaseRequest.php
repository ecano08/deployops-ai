<?php

namespace App\Http\Requests;

use App\Models\EvaluationCase;
use App\Models\EvaluationDataset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluationCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dataset = $this->route('evaluation_dataset');
        $evaluationCase = $this->route('evaluation_case');

        return $dataset instanceof EvaluationDataset
            && $evaluationCase instanceof EvaluationCase
            && $evaluationCase->evaluation_dataset_id === $dataset->id
            && ($this->user()?->can('update', $dataset) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'input' => ['sometimes', 'required', 'string'],
            'expected_behavior' => ['sometimes', 'required', 'string'],
            'expected_tools' => ['nullable', 'array'],
            'expected_tools.*' => ['string'],
            'expected_sources' => ['nullable', 'array'],
            'expected_sources.*' => ['string'],
        ];
    }
}
