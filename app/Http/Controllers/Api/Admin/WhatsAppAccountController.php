<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantWhatsAppAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = TenantWhatsAppAccount::with('tenant:id,company_name')
            ->orderBy('tenant_id')
            ->get(['id', 'tenant_id', 'phone_number_id', 'waba_id', 'display_phone_number', 'status', 'created_at', 'updated_at']);

        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $inputData = $request->all();
        $inputData['tenant_id'] = $tenantId;

        $data = $request->validate([
            'tenant_id' => ['sometimes', 'required_without:business_id', 'integer', 'exists:tenants,id'],
            'business_id' => ['sometimes', 'required_without:tenant_id', 'integer', 'exists:tenants,id'],
            'phone_number_id' => [
                'required',
                'string',
                'max:100',
                'unique:tenant_whats_app_accounts,phone_number_id',
            ],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'display_phone_number' => ['nullable', 'string', 'max:50'],
            'access_token' => ['required', 'string', 'min:20'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $data['tenant_id'] = $tenantId;
        unset($data['business_id']);

        try {
            app(\App\Services\QuotaEnforcementService::class)->reserveQuota(
                tenantId: (int) $tenantId,
                metric: \App\Services\QuotaEnforcementService::METRIC_WHATSAPP_NUMBERS,
                quantity: 1,
                idempotencyKey: 'wa_number_' . $data['phone_number_id'],
                sourceType: 'whatsapp_account'
            );
        } catch (\App\Exceptions\QuotaExceededException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'error' => 'Quota exceeded.',
                'quota' => $exception->toArray(),
            ], 422);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $account = TenantWhatsAppAccount::create($data);
        return response()->json([
            'data' => $account->only(['id', 'tenant_id', 'phone_number_id', 'waba_id', 'display_phone_number', 'status', 'created_at']),
        ], 201);
    }

    public function update(Request $request, TenantWhatsAppAccount $account): JsonResponse
    {
        $data = $request->validate([
            'phone_number_id' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique(
                    'tenant_whats_app_accounts',
                    'phone_number_id'
                )->ignore($account->id),
            ],
            'display_phone_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'access_token' => ['sometimes', 'required', 'string', 'min:20'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ]);

        $account->fill($data)->save();

        return response()->json([
            'data' => $account->only(['id', 'tenant_id', 'phone_number_id', 'waba_id', 'display_phone_number', 'status', 'updated_at']),
        ]);
    }
}
