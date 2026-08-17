<?php

namespace App\Http\Requests;

use App\Models\Deployment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CopilotAskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        if (! $deployment instanceof Deployment) {
            return false;
        }

        return Gate::allows('view', $deployment);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:10'],
            'history.*.question' => ['required', 'string', 'max:2000'],
            'history.*.answer' => ['required', 'string', 'max:8000'],
            'history_deployment_id' => ['required_with:history', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $history = $this->input('history');

            if (! is_array($history) || $history === []) {
                return;
            }

            $deployment = $this->route('deployment');

            if (! $deployment instanceof Deployment) {
                return;
            }

            $historyDeploymentId = $this->input('history_deployment_id');

            if (! is_numeric($historyDeploymentId) || (int) $historyDeploymentId !== $deployment->id) {
                $validator->errors()->add(
                    'history_deployment_id',
                    'Conversation history must belong to the current deployment.',
                );
            }
        });
    }
}
