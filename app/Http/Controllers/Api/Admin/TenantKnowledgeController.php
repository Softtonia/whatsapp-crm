<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantKnowledgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $validator = Validator::make(['tenant_id' => $tenantId, 'is_active' => $request->input('is_active')], [
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = TenantKnowledge::where(
            'tenant_id',
            $tenantId
        );

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant knowledge retrieved successfully.',
            'data' => $query->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $inputData = $request->all();
        $inputData['tenant_id'] = $tenantId;

        $validator = Validator::make($inputData, [
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $knowledge = TenantKnowledge::create([
            'tenant_id' => $tenantId,
            'question' => $request->question,
            'answer' => $request->answer,
            'keywords' => $request->input('keywords', []),
            'is_active' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant knowledge created successfully. Approval is required before use.',
            'data' => $knowledge,
        ], 201);
    }

    public function update(Request $request, int $knowledge): JsonResponse
    {
        $knowledgeRecord = TenantKnowledge::find($knowledge);

        if (!$knowledgeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant knowledge not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'question' => ['sometimes', 'required', 'string', 'max:500'],
            'answer' => ['sometimes', 'required', 'string'],
            'keywords' => ['sometimes', 'nullable', 'array'],
            'keywords.*' => ['string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $contentChanged = false;

        foreach (['question', 'answer', 'keywords'] as $field) {
            if (array_key_exists($field, $data)) {
                $oldValue = $knowledgeRecord->{$field};
                $newValue = $data[$field];

                if ($field === 'keywords') {
                    $oldValue = $oldValue ?? [];
                    $newValue = $newValue ?? [];
                }

                if ($oldValue != $newValue) {
                    $contentChanged = true;
                    break;
                }
            }
        }

        if ($contentChanged) {
            $data['is_active'] = false;
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        } elseif (
            array_key_exists('is_active', $data) &&
            $data['is_active'] === true
        ) {
            $data['approved_by'] = $request->user()->id;
            $data['approved_at'] = now();
        } elseif (
            array_key_exists('is_active', $data) &&
            $data['is_active'] === false
        ) {
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }

        $knowledgeRecord->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Tenant knowledge updated successfully.',
            'data' => $knowledgeRecord->fresh(),
        ]);
    }

    public function destroy(int $knowledge): JsonResponse
    {
        $knowledgeRecord = TenantKnowledge::find($knowledge);

        if (!$knowledgeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant knowledge not found.',
            ], 404);
        }

        $knowledgeRecord->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tenant knowledge deleted successfully.',
        ]);
    }
}
