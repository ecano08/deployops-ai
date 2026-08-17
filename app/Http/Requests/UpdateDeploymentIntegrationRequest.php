<?php

namespace App\Http\Requests;

use App\Models\DeploymentIntegration;
use App\Services\IntegrationOutboundUrlValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeploymentIntegrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $integration = $this->route('deployment_integration');

        return $integration instanceof DeploymentIntegration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || $value === '') {
                    return;
                }

                if (! app(IntegrationOutboundUrlValidator::class)->isAllowed($value)) {
                    $fail('The base URL is not allowed.');
                }
            }],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'config' => ['sometimes', 'nullable', 'array'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function integrationAttributes(): array
    {
        $attributes = $this->safe()->only(['name', 'base_url', 'endpoint', 'config']);

        $secrets = $this->route('deployment_integration')->secrets ?? [];

        if (! is_array($secrets)) {
            $secrets = [];
        }

        if ($this->has('api_key')) {
            $apiKey = $this->validated('api_key');

            if ($apiKey === null || $apiKey === '') {
                unset($secrets['api_key']);
            } else {
                $secrets['api_key'] = $apiKey;
            }
        }

        if ($this->has('webhook_secret')) {
            $webhookSecret = $this->validated('webhook_secret');

            if ($webhookSecret === null || $webhookSecret === '') {
                unset($secrets['webhook_secret']);
            } else {
                $secrets['webhook_secret'] = $webhookSecret;
            }
        }

        if ($secrets !== []) {
            $attributes['secrets'] = $secrets;
        } elseif ($this->has('api_key') || $this->has('webhook_secret')) {
            $attributes['secrets'] = null;
        }

        return $attributes;
    }
}
