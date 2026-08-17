<?php

namespace App\Http\Requests;

use App\Enums\AiActionType;
use App\Enums\DeploymentStage;
use App\Models\AiProposedAction;
use App\Models\Deployment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiProposedActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        return $deployment instanceof Deployment
            && ($this->user()?->can('propose', [AiProposedAction::class, $deployment]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::enum(AiActionType::class)],
            'payload' => ['required', 'array'],
            'payload.stage' => [
                Rule::requiredIf(fn (): bool => $this->input('action_type') === AiActionType::UpdateDeploymentStage->value),
                Rule::enum(DeploymentStage::class),
            ],
        ];
    }
}
