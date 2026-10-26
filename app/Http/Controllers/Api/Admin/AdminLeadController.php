<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadPipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminLeadController extends Controller
{
    /**
     * List leads for a tenant with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $data = $request->validate([
            'tenant_id' => [
                'sometimes',
                'required_without:business_id',
                'integer',
                'exists:tenants,id',
            ],
            'business_id' => [
                'sometimes',
                'required_without:tenant_id',
                'integer',
                'exists:tenants,id',
            ],
            'status' => [
                'nullable',
                Rule::in(['open', 'won', 'lost']),
            ],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'pipeline_stage_id' => [
                'nullable',
                'integer',
                'exists:lead_pipeline_stages,id',
            ],
            'follow_up_due' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = (int) $tenantId;

        $query = Lead::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'contact:id,tenant_id,name,phone,email,company',
                'owner:id,first_name,last_name,email',
                'pipelineStage',
            ])
            ->latest();

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        // Owner filter is optional.
        if (isset($data['owner_id'])) {
            $this->validateOwner(
                $tenantId,
                (int) $data['owner_id']
            );

            $query->where('owner_id', $data['owner_id']);
        }

        if (isset($data['pipeline_stage_id'])) {
            $this->validateStage(
                $tenantId,
                (int) $data['pipeline_stage_id']
            );

            $query->where(
                'pipeline_stage_id',
                $data['pipeline_stage_id']
            );
        }

        if ($request->boolean('follow_up_due')) {
            $query->whereNotNull('follow_up_at')
                ->where('follow_up_at', '<=', now())
                ->where('status', 'open');
        }

        return response()->json(
            $query->paginate($data['per_page'] ?? 20)
        );
    }

    /**
     * Create a lead. Owner assignment is optional.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $data = $request->validate([
            'tenant_id' => [
                'sometimes',
                'required_without:business_id',
                'integer',
                'exists:tenants,id',
            ],
            'business_id' => [
                'sometimes',
                'required_without:tenant_id',
                'integer',
                'exists:tenants,id',
            ],
            'contact_id' => [
                'required',
                'integer',
                'exists:contacts,id',
            ],
            'owner_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'pipeline_stage_id' => [
                'nullable',
                'integer',
                'exists:lead_pipeline_stages,id',
            ],
            'title' => ['required', 'string', 'max:255'],
            'status' => [
                'nullable',
                Rule::in(['open', 'won', 'lost']),
            ],
            'priority' => [
                'nullable',
                Rule::in(['low', 'normal', 'high', 'urgent']),
            ],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $tenantId = (int) $tenantId;

        $this->validateContact(
            $tenantId,
            (int) $data['contact_id']
        );

        // Validate membership only if an owner was provided.
        if (!empty($data['owner_id'])) {
            $this->validateOwner(
                $tenantId,
                (int) $data['owner_id']
            );
        }

        $stage = null;

        if (!empty($data['pipeline_stage_id'])) {
            $stage = $this->validateStage(
                $tenantId,
                (int) $data['pipeline_stage_id']
            );
        }

        $status = $data['status'] ?? 'open';

        if ($stage?->is_won) {
            if (isset($data['status']) && $status !== 'won') {
                throw ValidationException::withMessages([
                    'status' => [
                        'A won pipeline stage requires status to be won.',
                    ],
                ]);
            }

            $status = 'won';
        }

        if ($stage?->is_lost) {
            if (isset($data['status']) && $status !== 'lost') {
                throw ValidationException::withMessages([
                    'status' => [
                        'A lost pipeline stage requires status to be lost.',
                    ],
                ]);
            }

            $status = 'lost';
        }

        $lead = DB::transaction(function () use (
            $data,
            $tenantId,
            $status
        ) {
            return Lead::create([
                'tenant_id' => $tenantId,
                'contact_id' => $data['contact_id'],
                'owner_id' => $data['owner_id'] ?? null,
                'pipeline_stage_id' => $data['pipeline_stage_id'] ?? null,
                'title' => $data['title'],
                'status' => $status,
                'priority' => $data['priority'] ?? 'normal',
                'follow_up_at' => $data['follow_up_at'] ?? null,
                'closed_at' => $status === 'open' ? null : now(),
            ]);
        });

        return response()->json([
            'message' => 'Lead created successfully.',
            'lead' => $lead->load([
                'contact',
                'owner:id,first_name,last_name,email',
                'pipelineStage',
            ]),
        ], 201);
    }

    /**
     * Show a lead and its notes.
     */
    public function show(Lead $lead): JsonResponse
    {
        $lead->load([
            'contact',
            'owner:id,first_name,last_name,email',
            'pipelineStage',
            'notes.author:id,first_name,last_name,email',
        ]);

        return response()->json([
            'lead' => $lead,
        ]);
    }

    /**
     * Update a lead.
     *
     * Omit owner_id to keep the current owner.
     * Send owner_id: null to remove the assignment.
     */
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:contacts,id',
            ],
            'owner_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'pipeline_stage_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:lead_pipeline_stages,id',
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => [
                'sometimes',
                'required',
                Rule::in(['open', 'won', 'lost']),
            ],
            'priority' => [
                'sometimes',
                'required',
                Rule::in(['low', 'normal', 'high', 'urgent']),
            ],
            'follow_up_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $businessId = (int) ($lead->tenant_id ?? $lead->business_id);

        if (isset($data['contact_id'])) {
            $this->validateContact(
                $businessId,
                (int) $data['contact_id']
            );
        }

        // Null clears the owner; a non-null owner must be an active member.
        if (
            array_key_exists('owner_id', $data)
            && $data['owner_id'] !== null
        ) {
            $this->validateOwner(
                $businessId,
                (int) $data['owner_id']
            );
        }

        $stage = null;

        if (
            array_key_exists('pipeline_stage_id', $data)
            && $data['pipeline_stage_id'] !== null
        ) {
            $stage = $this->validateStage(
                $businessId,
                (int) $data['pipeline_stage_id']
            );
        } elseif (
            !array_key_exists('pipeline_stage_id', $data)
            && $lead->pipeline_stage_id !== null
        ) {
            $stage = $lead->pipelineStage;
        }

        $status = $data['status'] ?? $lead->status;

        if ($stage?->is_won) {
            if (isset($data['status']) && $status !== 'won') {
                throw ValidationException::withMessages([
                    'status' => [
                        'A won pipeline stage requires status to be won.',
                    ],
                ]);
            }

            $status = 'won';
        }

        if ($stage?->is_lost) {
            if (isset($data['status']) && $status !== 'lost') {
                throw ValidationException::withMessages([
                    'status' => [
                        'A lost pipeline stage requires status to be lost.',
                    ],
                ]);
            }

            $status = 'lost';
        }

        if (isset($data['status'])) {
            $data['closed_at'] = $status === 'open'
                ? null
                : ($lead->closed_at ?? now());
        } elseif (
            array_key_exists('pipeline_stage_id', $data)
            && $status !== $lead->status
        ) {
            $data['status'] = $status;
            $data['closed_at'] = $status === 'open'
                ? null
                : ($lead->closed_at ?? now());
        }

        $lead->update($data);

        return response()->json([
            'message' => 'Lead updated successfully.',
            'lead' => $lead->fresh()->load([
                'contact',
                'owner:id,first_name,last_name,email',
                'pipelineStage',
            ]),
        ]);
    }

    /**
     * Add a note to a lead.
     */
    public function addNote(
        Request $request,
        Lead $lead
    ): JsonResponse {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:10000'],
        ]);

        $note = LeadNote::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'note' => $data['note'],
        ]);

        return response()->json([
            'message' => 'Lead note added successfully.',
            'note' => $note->load(
                'author:id,first_name,last_name,email'
            ),
        ], 201);
    }

    /**
     * List pipeline stages for a tenant.
     */
    public function pipelineStages(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $data = $request->validate([
            'tenant_id' => [
                'sometimes',
                'required_without:business_id',
                'integer',
                'exists:tenants,id',
            ],
            'business_id' => [
                'sometimes',
                'required_without:tenant_id',
                'integer',
                'exists:tenants,id',
            ],
        ]);

        $stages = LeadPipelineStage::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'stages' => $stages,
        ]);
    }

    /**
     * Create a pipeline stage.
     */
    public function createPipelineStage(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $data = $request->validate([
            'tenant_id' => [
                'sometimes',
                'required_without:business_id',
                'integer',
                'exists:tenants,id',
            ],
            'business_id' => [
                'sometimes',
                'required_without:tenant_id',
                'integer',
                'exists:tenants,id',
            ],
            'name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_won' => ['nullable', 'boolean'],
            'is_lost' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['is_won']) && !empty($data['is_lost'])) {
            throw ValidationException::withMessages([
                'is_won' => [
                    'A stage cannot be both won and lost.',
                ],
            ]);
        }

        $name = trim($data['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['The stage name cannot be empty.'],
            ]);
        }

        $duplicate = LeadPipelineStage::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => [
                    'A pipeline stage with this name already exists.',
                ],
            ]);
        }

        $stage = LeadPipelineStage::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'position' => $data['position'] ?? 0,
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
        ]);

        return response()->json([
            'message' => 'Pipeline stage created successfully.',
            'stage' => $stage,
        ], 201);
    }

    /**
     * Validate that a contact belongs to the tenant.
     */
    private function validateContact(
        int $tenantId,
        int $contactId
    ): Contact {
        $contact = Contact::query()
            ->whereKey($contactId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$contact) {
            throw ValidationException::withMessages([
                'contact_id' => [
                    'The selected contact does not belong to this tenant.',
                ],
            ]);
        }

        return $contact;
    }

    /**
     * Validate that the owner is an active tenant member.
     */
    private function validateOwner(
        int $tenantId,
        int $ownerId
    ): TenantUser {
        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerId)
            ->where('status', 'active')
            ->first();

        if (!$membership) {
            throw ValidationException::withMessages([
                'owner_id' => [
                    'The selected owner is not an active member of this tenant.',
                ],
            ]);
        }

        return $membership;
    }

    /**
     * Validate that a pipeline stage belongs to the tenant.
     */
    private function validateStage(
        int $tenantId,
        int $stageId
    ): LeadPipelineStage {
        $stage = LeadPipelineStage::query()
            ->whereKey($stageId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$stage) {
            throw ValidationException::withMessages([
                'pipeline_stage_id' => [
                    'The selected pipeline stage does not belong to this tenant.',
                ],
            ]);
        }

        return $stage;
    }
}
