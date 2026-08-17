<?php

namespace App\Http\Requests;

use App\Models\EvaluationDataset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluationDatasetRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
