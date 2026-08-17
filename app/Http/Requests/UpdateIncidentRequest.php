<?php

namespace App\Http\Requests;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('incident')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'severity' => ['sometimes', Rule::in(IncidentSeverity::values())],
            'status' => ['sometimes', Rule::in(IncidentStatus::values())],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
