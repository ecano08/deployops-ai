<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class BuildGroundedContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        if (! $deployment instanceof Deployment) {
            return false;
        }

        return Gate::allows('view', $deployment);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:2000'],
        ];
    }
}
