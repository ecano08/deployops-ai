<?php

namespace App\Http\Requests;

use App\Models\ProjectFact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $projectFact = $this->route('project_fact');

        return $projectFact instanceof ProjectFact
            && ($this->user()?->can('update', $projectFact) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:255'],
            'key' => ['sometimes', 'string', 'max:255'],
            'value' => ['sometimes', 'string', 'max:5000'],
            'source_reference' => ['nullable', 'string', 'max:5000'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
