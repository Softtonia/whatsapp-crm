<?php

namespace App\Http\Requests\Admin\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin') || $user->roles()->whereIn('slug', ['platform_owner', 'super_admin'])->exists()) {
            return true;
        }

        return $user->can('plans.create') || $user->hasPermissionTo('plans.create', 'web');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                'unique:subscription_plans,code',
            ],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:180',
                'alpha_dash',
                'unique:subscription_plans,slug',
            ],
            'description' => ['nullable', 'string', 'max:2000'],

            'price' => ['required', 'numeric', 'min:0'],
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'alpha',
            ],
            'billing_cycle' => [
                'required',
                Rule::in(['monthly', 'yearly', 'custom']),
            ],

            'is_active' => ['sometimes', 'boolean'],
            'plan_type' => [
                'sometimes',
                Rule::in(['standard', 'custom', 'enterprise']),
            ],
            'status' => [
                'sometimes',
                Rule::in(['draft', 'published']),
            ],
            'visibility' => [
                'sometimes',
                Rule::in(['public', 'private']),
            ],
            'available_for_signup' => ['sometimes', 'boolean'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'trial_eligibility' => ['nullable', 'array'],
            'setup_fee' => ['sometimes', 'numeric', 'min:0'],
            'setup_fee_required' => ['sometimes', 'boolean'],
            'onboarding_config' => ['nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],

            'features' => ['nullable', 'array'],
            'max_whatsapp_messages' => ['nullable', 'integer', 'min:0'],
            'max_ai_replies' => ['nullable', 'integer', 'min:0'],
            'max_team_members' => ['nullable', 'integer', 'min:0'],
            'max_whatsapp_accounts' => ['nullable', 'integer', 'min:0'],
            'max_contacts' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge([
                'code' => strtoupper(trim((string) $this->input('code'))),
            ]);
        }

        if ($this->filled('currency')) {
            $this->merge([
                'currency' => strtoupper(trim((string) $this->input('currency'))),
            ]);
        }
    }
}