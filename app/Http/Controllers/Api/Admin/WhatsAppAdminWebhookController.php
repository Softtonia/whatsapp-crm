<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppAdminWebhookController extends Controller
{
    /**
     * GET /api/auth/admin/whatsapp/webhooks
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.webhooks.read');

        $query = WhatsAppWebhookEvent::with('tenant:id,company_name');

        if ($request->filled('processing_status')) {
            $query->where('processing_status', $request->query('processing_status'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->query('event_type'));
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->query('tenant_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 20)));
        $events = $query->orderByDesc('id')->paginate($perPage);

        $data = $events->getCollection()->map(function ($evt) {
            return [
                'id' => $evt->id,
                'event_id' => $evt->event_id,
                'event_type' => $evt->event_type,
                'tenant' => [
                    'id' => $evt->tenant?->id,
                    'name' => $evt->tenant?->company_name,
                ],
                'phone_number_id' => $evt->phone_number_id,
                'processing_status' => $evt->processing_status,
                'attempt_count' => $evt->attempt_count,
                'last_error' => $evt->last_error,
                'last_attempt_at' => $evt->last_attempt_at?->toIso8601String(),
                'processed_at' => $evt->processed_at?->toIso8601String(),
                'next_retry_at' => $evt->next_retry_at?->toIso8601String(),
                'received_at' => $evt->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * GET /api/auth/admin/whatsapp/webhooks/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.webhooks.read');

        $event = WhatsAppWebhookEvent::with('tenant:id,company_name')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $event->id,
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'tenant' => [
                    'id' => $event->tenant?->id,
                    'name' => $event->tenant?->company_name,
                ],
                'phone_number_id' => $event->phone_number_id,
                'waba_id' => $event->waba_id,
                'processing_status' => $event->processing_status,
                'attempt_count' => $event->attempt_count,
                'last_error' => $event->last_error,
                'last_attempt_at' => $event->last_attempt_at?->toIso8601String(),
                'processed_at' => $event->processed_at?->toIso8601String(),
                'next_retry_at' => $event->next_retry_at?->toIso8601String(),
                'received_at' => $event->created_at?->toIso8601String(),
                'payload_summary' => [
                    'contacts_count' => count($event->payload['contacts'] ?? []),
                    'messages_count' => count($event->payload['messages'] ?? []),
                    'statuses_count' => count($event->payload['statuses'] ?? []),
                ],
            ],
        ]);
    }

    /**
     * POST /api/auth/admin/whatsapp/webhooks/{id}/retry
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.webhooks.retry');

        $event = WhatsAppWebhookEvent::findOrFail($id);

        if ($event->processing_status === 'processed') {
            return response()->json([
                'message' => 'Event has already been successfully processed and cannot be retried.',
                'error' => 'already_processed',
            ], 409);
        }

        // Atomically transition status back to received / queued
        $event->update([
            'processing_status' => 'received',
            'last_error' => null,
            'next_retry_at' => now(),
        ]);

        ProcessWhatsAppWebhookJob::dispatchSync($event);
        $event->refresh();

        return response()->json([
            'message' => 'Webhook event retry executed.',
            'data' => [
                'id' => $event->id,
                'processing_status' => $event->processing_status,
                'attempt_count' => $event->attempt_count,
                'last_error' => $event->last_error,
                'processed_at' => $event->processed_at?->toIso8601String(),
            ],
        ]);
    }

    protected function authorizeAdmin($user, string $permission): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $isSuperAdmin = $user->hasRole('Super Admin') || $user->hasRole('super_admin');
        if (! $isSuperAdmin && ! $user->can($permission) && ! $user->hasPermissionTo($permission, 'web')) {
            abort(403, "Forbidden. Required permission: {$permission}.");
        }
    }
}
