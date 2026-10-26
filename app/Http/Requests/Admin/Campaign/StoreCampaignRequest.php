<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Enums\CampaignEvent;
use App\Enums\CampaignSendType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'send_type' => ['required', 'string', Rule::enum(CampaignSendType::class)],
            'event' => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => $this->input('send_type') === CampaignSendType::TRIGGER->value),
                Rule::enum(CampaignEvent::class),
            ],
            'target_users' => ['nullable', 'array'],
            'target_users.*' => ['integer', 'exists:users,id'],
            'scheduled_at' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('send_type') === CampaignSendType::SCHEDULE->value),
                function ($attribute, $value, $fail) {
                    if (blank($value)) {
                        return;
                    }
                    try {
                        \Carbon\Carbon::parse($value);
                    } catch (\Throwable $e) {
                        $fail('The scheduled at field must be a valid date and time (e.g. 2026-10-16 3:20 PM or 2026-10-16 15:20:00).');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Prepare the data for validation and database insertion.
     */
    protected function passedValidation(): void
    {
        if ($this->filled('scheduled_at')) {
            try {
                $parsed = \Carbon\Carbon::parse($this->input('scheduled_at'))->format('Y-m-d H:i:s');
                $this->merge(['scheduled_at' => $parsed]);
            } catch (\Throwable) {
                // If parsing fails, validation would have already caught it.
            }
        }
    }
}
