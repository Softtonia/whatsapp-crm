<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\TenantUser;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $filters = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'business_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'status' => ['nullable', Rule::in(['open', 'pending', 'resolved'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Conversation::query()
            ->with(['assignee:id,first_name,last_name,email', 'whatsappAccount:id,tenant_id,display_phone_number'])
            ->withCount('messages');

        if ($tenantId) $query->where('tenant_id', $tenantId);
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        if (isset($filters['assigned_to'])) $query->where('assigned_to', $filters['assigned_to']);
        if (! empty($filters['unread_only'])) $query->where('unread_count', '>', 0);

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('customer_phone', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('last_message_preview', 'like', "%{$term}%")
                    ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', "%{$term}%"));
            });
        }

        return response()->json($query->orderByDesc('last_message_at')->paginate($filters['per_page'] ?? 25));
    }

    public function show(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'data' => $conversation->load([
                'assignee:id,first_name,last_name,email',
                'whatsappAccount:id,tenant_id,display_phone_number,phone_number_id',
            ]),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $messages = $conversation->messages()
            ->with('sender:id,first_name,last_name,email')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json($messages);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(['open', 'pending', 'resolved'])],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'is_human_takeover' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $isActiveMember = \App\Models\TenantUser::query()
                ->where('tenant_id', $conversation->tenant_id)
                ->where('user_id', $data['assigned_to'])
                ->where('status', 'active')
                ->exists();

            if (! $isActiveMember) {
                return response()->json([
                    'message' => 'Assignee must be an active member of this business.',
                    'errors' => ['assigned_to' => ['Selected user is not an active business member.']],
                ], 422);
            }
        }

        $conversation->fill($data)->save();

        return response()->json(['data' => $conversation->fresh()->load('assignee:id,first_name,last_name,email')]);
    }

    public function markRead(Conversation $conversation): JsonResponse
    {
        $conversation->update(['unread_count' => 0]);

        return response()->json(['message' => 'Conversation marked as read.']);
    }

    public function addNote(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $message = $conversation->messages()->create([
            'sent_by_user_id' => $request->user()->id,
            'direction' => 'internal',
            'type' => 'note',
            'body' => $data['body'],
            'status' => 'internal',
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => '[Internal note] '.Str::limit($data['body'], 450),
        ]);

        return response()->json(['data' => $message], 201);
    }

    public function sendMessage(Request $request, Conversation $conversation, WhatsAppCloudApiService $cloud): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['text', 'image', 'document', 'video', 'audio'])],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'media_id' => ['nullable', 'string', 'max:255'],
            'link' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'mime_type' => ['nullable', 'string', 'max:150'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $message = $cloud->send($conversation, $request->user(), $data + ['type' => $data['type'] ?? 'text']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json(['data' => $message], 201);
    }

    public function media(WhatsAppMessage $message, WhatsAppCloudApiService $cloud): Response|JsonResponse
    {
        if (! in_array($message->direction, ['inbound', 'outbound'], true) || ! $message->media_id) {
            return response()->json(['message' => 'No downloadable media is attached to this message.'], 404);
        }

        try {
            $media = $cloud->downloadInboundMedia($message);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        $filename = $media['filename'] ?: ('whatsapp-media-'.$message->id);
        return response($media['bytes'], 200, [
            'Content-Type' => $media['mime_type'] ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', basename($filename)).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
