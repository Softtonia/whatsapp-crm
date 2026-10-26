<?php

namespace App\Http\Requests\Admin\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPlanRequest extends FormRequest
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

        return $user->can('plans.update') || $user->hasPermissionTo('plans.update', 'web');
    }


    public function rules(): array
    {
        $plan = $this->route('plan');
        $planId = $plan instanceof \App\Models\SubscriptionPlan
            ? $plan->id
            : $plan;

        return [
            // Plan code is intentionally immutable.
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:180',
                'alpha_dash',
                Rule::unique('subscription_plans', 'slug')->ignore($planId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'alpha'],
            'billing_cycle' => [
                'sometimes',
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
            'trial_eligibility' => ['sometimes', 'nullable', 'array'],
            'setup_fee' => ['sometimes', 'numeric', 'min:0'],
            'setup_fee_required' => ['sometimes', 'boolean'],
            'onboarding_config' => ['sometimes', 'nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],

            'features' => ['sometimes', 'nullable', 'array'],
            'max_whatsapp_messages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_ai_replies' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_team_members' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_whatsapp_accounts' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_contacts' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
