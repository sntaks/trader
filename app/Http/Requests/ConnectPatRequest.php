<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConnectPatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pat_token' => ['required', 'string', 'min:10'],
            'redirect_to' => ['nullable', 'string', 'in:account,copy-trading'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pat_token.required' => 'Paste your Deriv API token.',
            'pat_token.min' => 'That token is too short — copy the whole token from Deriv.',
        ];
    }
}
