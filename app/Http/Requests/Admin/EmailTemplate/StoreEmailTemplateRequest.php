<?php

namespace App\Http\Requests\Admin\EmailTemplate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Z0-9_]+$/',
                'unique:email_templates,key',
            ],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'type' => ['sometimes', 'required', 'string', 'in:html,plain,HTML,PLAIN'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The template key must only contain uppercase letters, numbers, and underscores.',
            'type.in' => 'The template type must be either "plain" or "html".',
        ];
    }
}
