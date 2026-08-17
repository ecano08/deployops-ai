<?php

namespace App\Http\Requests;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use App\Models\Deployment;
use App\Models\DeploymentIntegration;
use App\Models\Workspace;
use App\Services\IntegrationOutboundUrlValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeploymentIntegrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            && ($this->user()?->can('create', [DeploymentIntegration::class, $workspace]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->input('type', IntegrationType::RestApi->value);

        return [
            'type' => ['required', Rule::enum(IntegrationType::class)],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => [
                Rule::requiredIf($type === IntegrationType::RestApi->value),
                'nullable',
                'string',
                'max:2048',
                'url',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    if (! app(IntegrationOutboundUrlValidator::class)->isAllowed($value)) {
                        $fail('The base URL is not allowed.');
                    }
                },
            ],
            'endpoint' => ['nullable', 'string', 'max:2048'],
            'config' => ['nullable', 'array'],
            'api_key' => ['nullable', 'string', 'max:2048'],
            'webhook_secret' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function integrationAttributes(Workspace $workspace, Deployment $deployment): array
    {
        $secrets = [];

        if ($this->filled('api_key')) {
            $secrets['api_key'] = $this->validated('api_key');
        }

        if ($this->filled('webhook_secret')) {
            $secrets['webhook_secret'] = $this->validated('webhook_secret');
        }

        return [
            'workspace_id' => $workspace->id,
            'deployment_id' => $deployment->id,
            'type' => $this->validated('type'),
            'name' => $this->validated('name'),
            'base_url' => $this->validated('base_url'),
            'endpoint' => $this->validated('endpoint'),
            'config' => $this->validated('config'),
            'secrets' => $secrets !== [] ? $secrets : null,
            'status' => IntegrationStatus::Disconnected,
        ];
    }
}
