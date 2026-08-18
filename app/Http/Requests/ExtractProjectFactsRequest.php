<?php

namespace App\Http\Requests;

use App\Models\ProjectFact;
use Illuminate\Foundation\Http\FormRequest;

class ExtractProjectFactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deployment = $this->route('deployment');

        return $deployment !== null
            && ($this->user()?->can('extract', [ProjectFact::class, $deployment]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
