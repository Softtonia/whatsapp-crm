<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiReplyDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AiReplyDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $validator = Validator::make($request->all(), [
            'tenant_id' => ['sometimes', 'required_without:business_id', 'integer', 'exists:tenants,id'],
            'business_id' => ['sometimes', 'required_without:tenant_id', 'integer', 'exists:tenants,id'],
            'conversation_id' => ['sometimes', 'integer', 'exists:conversations,id'],
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = AiReplyDraft::where(
            'tenant_id',
            $tenantId
        );

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->conversation_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'AI reply drafts retrieved successfully.',
            'data' => $query->latest()->get(),
        ]);
    }

    public function update(Request $request, int $draft): JsonResponse
    {
        $replyDraft = AiReplyDraft::find($draft);

        if (!$replyDraft) {
            return response()->json([
                'success' => false,
                'message' => 'AI reply draft not found.',
            ], 404);
        }

        if ($replyDraft->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending drafts can be edited.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'edited_reply' => ['required', 'string', 'max:10000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $replyDraft->update([
            'edited_reply' => $request->input('edited_reply'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'AI reply draft updated successfully.',
            'data' => $replyDraft->fresh(),
        ]);
    }

    public function approve(Request $request, int $draft): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $draft) {
            $replyDraft = AiReplyDraft::whereKey($draft)
                ->lockForUpdate()
                ->first();

            if (!$replyDraft) {
                return response()->json([
                    'success' => false,
                    'message' => 'AI reply draft not found.',
                ], 404);
            }

            if ($replyDraft->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending drafts can be approved.',
                ], 422);
            }

            $replyDraft->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'AI reply draft approved successfully. No WhatsApp message was sent.',
                'data' => $replyDraft->fresh(),
            ]);
        });

        return $result;
    }

    public function reject(Request $request, int $draft): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $draft) {
            $replyDraft = AiReplyDraft::whereKey($draft)
                ->lockForUpdate()
                ->first();

            if (!$replyDraft) {
                return response()->json([
                    'success' => false,
                    'message' => 'AI reply draft not found.',
                ], 404);
            }

            if ($replyDraft->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending drafts can be rejected.',
                ], 422);
            }

            $replyDraft->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'AI reply draft rejected successfully.',
                'data' => $replyDraft->fresh(),
            ]);
        });

        return $result;
    }
}
