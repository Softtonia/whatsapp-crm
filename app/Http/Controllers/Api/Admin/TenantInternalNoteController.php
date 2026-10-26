<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInternalNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantInternalNoteController extends Controller
{
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $filters = $request->validate([
            'note_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_pinned' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $notes = TenantInternalNote::query()
            ->where('tenant_id', $tenant->id)
            ->with('author:id,first_name,last_name,email')
            ->when(
                $filters['note_type'] ?? null,
                fn ($query, $type) => $query->where('note_type', $type)
            )
            ->when(
                array_key_exists('is_pinned', $filters)
                    && $filters['is_pinned'] !== null,
                fn ($query) => $query->where(
                    'is_pinned',
                    $filters['is_pinned']
                )
            )
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json($notes);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'note_type' => [
                'sometimes',
                'string',
                'max:50',
                'in:support,billing,technical,compliance,general',
            ],
            'note' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $note = TenantInternalNote::create([
            'tenant_id' => $tenant->id,
            'author_user_id' => $request->user()->id,
            'note_type' => $validated['note_type'] ?? 'support',
            'note' => $validated['note'],
            'is_pinned' => $validated['is_pinned'] ?? false,
        ]);

        return response()->json([
            'message' => 'Internal note created successfully.',
            'data' => $note->load('author:id,first_name,last_name,email'),
        ], 201);
    }

    public function update(
        Request $request,
        Tenant $tenant,
        TenantInternalNote $note
    ): JsonResponse {
        $this->ensureNoteBelongsToTenant($tenant, $note);

        $validated = $request->validate([
            'note_type' => [
                'sometimes',
                'string',
                'max:50',
                'in:support,billing,technical,compliance,general',
            ],
            'note' => ['sometimes', 'required', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $note->update($validated);

        return response()->json([
            'message' => 'Internal note updated successfully.',
            'data' => $note->fresh()->load(
                'author:id,first_name,last_name,email'
            ),
        ]);
    }

    public function destroy(
        Tenant $tenant,
        TenantInternalNote $note
    ): JsonResponse {
        $this->ensureNoteBelongsToTenant($tenant, $note);

        $note->delete();

        return response()->json([
            'message' => 'Internal note deleted successfully.',
        ]);
    }

    public function togglePin(
        Tenant $tenant,
        TenantInternalNote $note
    ): JsonResponse {
        $this->ensureNoteBelongsToTenant($tenant, $note);

        $note->is_pinned = ! $note->is_pinned;
        $note->save();

        return response()->json([
            'message' => $note->is_pinned
                ? 'Internal note pinned successfully.'
                : 'Internal note unpinned successfully.',
            'data' => $note->fresh()->load(
                'author:id,first_name,last_name,email'
            ),
        ]);
    }

    private function ensureNoteBelongsToTenant(
        Tenant $tenant,
        TenantInternalNote $note
    ): void {
        abort_unless(
            (int) $note->tenant_id === (int) $tenant->id,
            404,
            'Internal note not found for this tenant.'
        );
    }
}
