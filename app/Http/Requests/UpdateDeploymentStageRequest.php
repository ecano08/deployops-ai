<?php

namespace App\Http\Requests;

use App\Enums\DeploymentStage;
use App\Models\Deployment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeploymentStageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        return $deployment instanceof Deployment
            && ($this->user()?->can('changeStage', $deployment) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::enum(DeploymentStage::class)],
        ];
    }
}
