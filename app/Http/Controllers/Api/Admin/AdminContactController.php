<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AdminContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $data = $request->validate([
            'tenant_id' => ['sometimes', 'required_without:business_id', 'integer', 'exists:tenants,id'],
            'business_id' => ['sometimes', 'required_without:tenant_id', 'integer', 'exists:tenants,id'],
            'search' => ['nullable', 'string', 'max:150'],
            'source' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Contact::query()
            ->where('tenant_id', $tenantId)
            ->withCount(['interactions', 'leads'])
            ->latest();

        if (! empty($data['search'])) {
            $query->search($data['search']);
        }

        if (! empty($data['source'])) {
            $query->where('source', $data['source']);
        }

        return response()->json($query->paginate($data['per_page'] ?? 20));
    }

    public function store(
        Request $request,
        ContactService $contactService
    ): JsonResponse {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $data = $request->validate([
            'tenant_id' => ['sometimes', 'required_without:business_id', 'integer', 'exists:tenants,id'],
            'business_id' => ['sometimes', 'required_without:tenant_id', 'integer', 'exists:tenants,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'custom_fields' => ['nullable', 'array'],
        ]);

        try {
            $contact = $contactService->saveContact(
                (int) $tenantId,
                $data
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'phone' => [$exception->getMessage()],
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Contact created successfully.',
            'contact' => $contact,
        ], 201);
    }

    public function show(Contact $contact): JsonResponse
    {
        $contact->load([
            'interactions' => fn ($query) => $query
                ->latest('occurred_at')
                ->limit(100),
            'leads.owner:id,first_name,last_name,email',
            'leads.pipelineStage',
            'leads.notes.author:id,first_name,last_name,email',
            'conversations',
        ]);

        return response()->json(['contact' => $contact]);
    }

    public function update(
        Request $request,
        Contact $contact,
        ContactService $contactService
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'string', 'max:50'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:100'],
            'custom_fields' => ['sometimes', 'array'],
        ]);

        $data = array_merge($contact->only([
            'name', 'phone', 'email', 'company', 'source',
            'tags', 'custom_fields',
        ]), $data);

        try {
            $contact = $contactService->saveContact(
                (int) ($contact->tenant_id ?? $contact->business_id),
                $data,
                $contact
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'phone' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'message' => 'Contact updated successfully.',
            'contact' => $contact,
        ]);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        // Avoid deleting contacts with active leads.
        if ($contact->leads()->where('status', 'open')->exists()) {
            return response()->json([
                'message' => 'Close or reassign open leads before deleting this contact.',
            ], 422);
        }

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully.',
        ]);
    }
}