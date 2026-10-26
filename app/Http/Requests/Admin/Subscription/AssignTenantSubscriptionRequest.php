<?php

namespace App\Http\Requests\Admin\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTenantSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscription_plan_id' => [
                'required',
                'integer',
                Rule::exists('subscription_plans', 'id')
                    ->where(fn($query) => $query
                        ->where('is_active', true)
                        ->where('status', 'published')
                        ->where('available_for_signup', true)
                        ->whereNull('archived_at')
                        ->whereNull('deleted_at')),
            ],

            'starts_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'ends_at' => [
                'sometimes',
                'nullable',
                'date',
                'after:starts_at',
            ],

            'trial_ends_at' => [
                'sometimes',
                'nullable',
                'date',
                'after:starts_at',
            ],

            'status' => [
                'sometimes',
                Rule::in(['active', 'trial']),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
