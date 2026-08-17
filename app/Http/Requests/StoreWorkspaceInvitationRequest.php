<?php

namespace App\Http\Requests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        return $workspace instanceof Workspace
            && ($this->user()?->can('createInvitation', $workspace) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)->except(WorkspaceRole::Owner)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $workspace = $this->route('workspace');
                $user = User::query()->where('email', $this->string('email')->toString())->first();

                if ($workspace instanceof Workspace && $user?->belongsToWorkspace($workspace)) {
                    $validator->errors()->add('email', 'This user is already a member of the workspace.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => $this->string('email')->lower()->toString(),
            ]);
        }
    }
}
