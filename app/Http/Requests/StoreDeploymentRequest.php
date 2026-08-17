<?php

namespace App\Http\Requests;

use App\Enums\DeploymentStage;
use App\Models\Deployment;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeploymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            && ($this->user()?->can('create', [Deployment::class, $workspace]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'stage' => ['sometimes', Rule::enum(DeploymentStage::class)],
        ];
    }
}
