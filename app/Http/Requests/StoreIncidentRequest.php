<?php

namespace App\Http\Requests;

use App\Enums\IncidentSeverity;
use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Incident::class, $this->route('deployment')]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(IncidentSeverity::values())],
        ];
    }
}
