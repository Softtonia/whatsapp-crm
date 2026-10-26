<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTenantOffboardingJob;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantOffboardingJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TenantOffboardingController extends Controller
{
    private const RETENTION_DAYS = 90;

    private const ACTIVE_STATUSES = [
        'pending',
        'approved',
        'scheduled',
        'processing',
    ];

    private const FILTER_STATUSES = [
        'pending',
        'approved',
        'scheduled',
        'processing',
        'completed',
        'cancelled',
        'rejected',
        'failed',
    ];

    private const PURGE_ACTIVE_STATUSES = [
        'requested',
        'approved',
        'processing',
    ];


    private const PURGE_COUNT_TABLES = [
        'tenant_users',
        'contacts',
        'tenant_whats_app_accounts',
        'conversations',
        'whatsapp_messages',
        'contact_interactions',
        'lead_pipeline_stages',
        'leads',
        'lead_notes',
        'tenant_knowledge',
        'ai_reply_drafts',
        'products',
        'tenant_subscriptions',
        'tenant_subscription_events',
        'tenant_usage_records',
        'tenant_internal_notes',
        'tenant_exports',
        'tenant_health_checks',
        'tenant_status_history',
        'tenant_activity_logs',
        'tenant_bulk_operations',
        'tenant_bulk_operation_items',
        'tenant_offboarding_jobs',
        'tenant_offboarding_steps',
    ];


    private function relations(): array
    {
        return [
            'tenant:id,company_name,slug,status',
            'requester:id,email',
            'approver:id,email',
            'steps',
        ];
    }

    private function isPurgeRequest(TenantOffboardingJob $job): bool
    {
        return ($job->options['operation'] ?? null) === 'purge_request'
            || ($job->purge_status ?? 'not_requested') !== 'not_requested';
    }

    private function hasPermission(
        Request $request,
        string $permission
    ): bool {
        $user = $request->user();

        return $user !== null && $user->can($permission);
    }

    private function permissionDenied(string $permission): JsonResponse
    {
        return response()->json([
            'message' => 'You are not authorized to perform this action.',
            'required_permission' => $permission,
        ], 403);
    }

    /**
     * List tenant offboarding requests.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(self::FILTER_STATUSES),
            ],
            'tenant_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists((new Tenant())->getTable(), 'id'),
            ],
            'purge_status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'not_requested',
                    'requested',
                    'approved',
                    'rejected',
                    'processing',
                    'completed',
                    'blocked',
                ]),
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $jobs = TenantOffboardingJob::query()
            ->with($this->relations())
            ->when(
                ! empty($validated['status']),
                fn($query) => $query->where(
                    'status',
                    $validated['status']
                )
            )
            ->when(
                ! empty($validated['tenant_id']),
                fn($query) => $query->where(
                    'tenant_id',
                    $validated['tenant_id']
                )
            )
            ->when(
                ! empty($validated['purge_status']),
                fn($query) => $query->where(
                    'purge_status',
                    $validated['purge_status']
                )
            )
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $jobs->items(),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    /**
     * Create a normal tenant offboarding request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => [
                'required',
                'integer',
                Rule::exists((new Tenant())->getTable(), 'id'),
            ],
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:1000',
            ],
            'scheduled_for' => [
                'sometimes',
                'nullable',
                'date',
                'after:now',
            ],
            'options' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ]);

        $result = DB::transaction(function () use (
            $request,
            $validated
        ): array {
            $tenant = Tenant::query()
                ->whereKey($validated['tenant_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $existingRequest = TenantOffboardingJob::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->exists();

            if ($existingRequest) {
                return [
                    'conflict' => true,
                    'message' => 'An active offboarding request already exists for this tenant.',
                ];
            }

            if ($tenant->status === 'archived') {
                return [
                    'conflict' => true,
                    'message' => 'This tenant is already archived.',
                ];
            }

            $job = TenantOffboardingJob::query()->create([
                'tenant_id' => $tenant->id,
                'requested_by' => $request->user()->id,
                'approved_by' => null,
                'status' => ! empty($validated['scheduled_for'])
                    ? 'scheduled'
                    : 'pending',
                'reason' => $validated['reason'],
                'options' => $validated['options'] ?? [],
                'metadata' => [
                    'data_deleted' => false,
                ],
                'scheduled_for' => $validated['scheduled_for'] ?? null,
            ]);

            return [
                'conflict' => false,
                'job' => $job,
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => $result['message'],
            ], 409);
        }

        return response()->json([
            'message' => 'Offboarding request created. Tenant data has not been deleted.',
            'data' => $result['job']->load($this->relations()),
        ], 201);
    }

    /**
     * Show an offboarding request.
     */
    public function show(TenantOffboardingJob $job): JsonResponse
    {
        return response()->json([
            'data' => $job->load($this->relations()),
        ]);
    }

    /**
     * Approve a normal offboarding request.
     */
    public function approve(
        Request $request,
        TenantOffboardingJob $job
    ): JsonResponse {
        $result = DB::transaction(function () use (
            $request,
            $job
        ): array {
            $locked = TenantOffboardingJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isPurgeRequest($locked)) {
                return [
                    'conflict' => true,
                    'message' => 'Purge requests must use the dedicated purge approval workflow.',
                ];
            }

            if (! in_array(
                $locked->status,
                ['pending', 'scheduled'],
                true
            )) {
                return [
                    'conflict' => true,
                    'message' => 'Only pending or scheduled requests can be approved.',
                ];
            }

            $locked->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'metadata' => array_merge(
                    $locked->metadata ?? [],
                    [
                        'approved_at' => now()->toISOString(),
                        'approved_by' => $request->user()->id,
                    ]
                ),
            ]);

            return [
                'conflict' => false,
                'job' => $locked->fresh(),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => $result['message'],
            ], 409);
        }

        $approvedJob = $result['job'];

        $isScheduledForFuture = $approvedJob->scheduled_for
            && $approvedJob->scheduled_for->isFuture();

        if (! $isScheduledForFuture) {
            ProcessTenantOffboardingJob::dispatch(
                $approvedJob->id
            );
        }

        return response()->json([
            'message' => $isScheduledForFuture
                ? 'Offboarding approved and scheduled for future execution.'
                : 'Offboarding approved and queued for execution.',
            'data' => $approvedJob->load($this->relations()),
        ]);
    }

    /**
     * Preview purge eligibility without deleting tenant data.
     */
    public function purgePreview(
        Request $request,
        Tenant $tenant
    ): JsonResponse {
        if (! $this->hasPermission($request, 'tenant.purge.preview')) {
            return $this->permissionDenied('tenant.purge.preview');
        }

        $preview = $this->buildPurgePreview($tenant->fresh());

        return response()->json([
            'message' => 'Purge eligibility preview generated. No data was deleted.',
            'data' => $preview,
        ]);
    }

    /**
     * Submit a purge request for independent approval.
     */
    public function requestPurge(
        Request $request,
        Tenant $tenant
    ): JsonResponse {
        if (! $this->hasPermission($request, 'tenant.purge.request')) {
            return $this->permissionDenied('tenant.purge.request');
        }

        $validated = $request->validate([
            'confirmation' => [
                'required',
                'in:PURGE',
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:2000',
            ],
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use (
            $tenant,
            $user,
            $validated
        ): array {
            $lockedTenant = Tenant::query()
                ->whereKey($tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $preview = $this->buildPurgePreview($lockedTenant);

            if ($lockedTenant->status !== 'archived') {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'Only archived tenants can be submitted for purge.',
                ];
            }

            if (! $lockedTenant->archived_at) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The tenant archive timestamp is missing.',
                ];
            }

            if (! $preview['retention']['completed']) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The 90-day retention period has not completed.',
                    'data' => [
                        'retention_until' => $preview['retention']['retention_until'],
                    ],
                ];
            }

            if ($preview['legal_hold_active']) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'Purge is blocked by an active legal hold.',
                ];
            }

            $existingRequest = TenantOffboardingJob::query()
                ->where('tenant_id', $lockedTenant->id)
                ->whereIn('purge_status', self::PURGE_ACTIVE_STATUSES)
                ->lockForUpdate()
                ->exists();

            if ($existingRequest) {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'An active purge request already exists for this tenant.',
                ];
            }

            $job = TenantOffboardingJob::query()->create([
                'tenant_id' => $lockedTenant->id,
                'requested_by' => $user->id,
                'approved_by' => null,
                'status' => 'pending',
                'reason' => $validated['reason'],
                'options' => [
                    'operation' => 'purge_request',
                ],
                'metadata' => [
                    'requested_at' => now()->toISOString(),
                    'retention_days' => self::RETENTION_DAYS,
                    'data_deleted' => false,
                ],
                'archived_at' => $lockedTenant->archived_at,
                'retention_until' => $preview['retention']['retention_until'],
                'purge_eligible_at' => $preview['retention']['retention_until'],
                'purge_status' => 'requested',
                'purge_requested_by' => $user->id,
                'purge_requested_at' => now(),
                'purge_reason' => $validated['reason'],
                'purge_preview' => $preview,
            ]);

            $this->writePurgeAudit(
                $lockedTenant,
                $user->id,
                'tenant.purge.requested',
                [
                    'request_id' => $job->id,
                    'purge_status' => 'requested',
                    'data_deleted' => false,
                ]
            );

            return [
                'error' => false,
                'job' => $job,
            ];
        });

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Purge request submitted. No tenant data was deleted.',
            'data' => [
                'request_id' => $result['job']->id,
                'tenant_id' => $result['job']->tenant_id,
                'purge_status' => $result['job']->purge_status,
                'retention_until' => $result['job']->retention_until?->toISOString(),
                'data_deleted' => false,
            ],
        ], 202);
    }

    /**
     * Approve a purge request without executing deletion.
     */
    public function approvePurge(
        Request $request,
        TenantOffboardingJob $job
    ): JsonResponse {
        if (! $this->hasPermission($request, 'tenant.purge.approve')) {
            return $this->permissionDenied('tenant.purge.approve');
        }

        $user = $request->user();

        $result = DB::transaction(function () use (
            $job,
            $user
        ): array {
            $lockedJob = TenantOffboardingJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isPurgeRequest($lockedJob)) {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'This is not a purge request.',
                ];
            }

            if ($lockedJob->purge_status !== 'requested') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'Only requested purge operations can be approved.',
                ];
            }

            if ((int) $lockedJob->purge_requested_by === (int) $user->id) {
                return [
                    'error' => true,
                    'status' => 403,
                    'message' => 'The requester cannot approve their own purge request.',
                ];
            }

            if (! $lockedJob->tenant_id) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The tenant record is no longer available.',
                ];
            }

            $tenant = Tenant::query()
                ->whereKey($lockedJob->tenant_id)
                ->lockForUpdate()
                ->first();

            if (! $tenant) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The tenant record is no longer available.',
                ];
            }

            $preview = $this->buildPurgePreview($tenant);

            if (! $preview['retention']['completed']) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The retention period is not complete.',
                    'data' => $preview,
                ];
            }

            if ($tenant->status !== 'archived') {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'The tenant must remain archived.',
                    'data' => $preview,
                ];
            }

            if ($preview['legal_hold_active']) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'An active legal hold prevents approval.',
                    'data' => $preview,
                ];
            }

            if (! $preview['dependency_checks_complete']) {
                return [
                    'error' => true,
                    'status' => 422,
                    'message' => 'Approval is blocked until comprehensive dependency and outstanding-obligation checks are implemented.',
                    'data' => $preview,
                ];
            }

            $lockedJob->forceFill([
                'purge_status' => 'approved',
                'purge_approved_by' => $user->id,
                'purge_approved_at' => now(),
                'purge_preview' => $preview,
                'metadata' => array_merge(
                    $lockedJob->metadata ?? [],
                    [
                        'purge_approved_at' => now()->toISOString(),
                        'purge_approved_by' => $user->id,
                        'data_deleted' => false,
                    ]
                ),
            ])->save();

            $this->writePurgeAudit(
                $tenant,
                $user->id,
                'tenant.purge.approved',
                [
                    'request_id' => $lockedJob->id,
                    'purge_status' => 'approved',
                    'data_deleted' => false,
                ]
            );

            return [
                'error' => false,
                'job' => $lockedJob->fresh(),
            ];
        });

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Purge request approved. No tenant data was deleted or queued for deletion.',
            'data' => $result['job']->load($this->relations()),
            'data_deleted' => false,
        ]);
    }

    /**
     * Reject a purge request.
     */
    public function rejectPurge(
        Request $request,
        TenantOffboardingJob $job
    ): JsonResponse {
        if (! $this->hasPermission($request, 'tenant.purge.reject')) {
            return $this->permissionDenied('tenant.purge.reject');
        }

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:2000',
            ],
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use (
            $job,
            $user,
            $validated
        ): array {
            $lockedJob = TenantOffboardingJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isPurgeRequest($lockedJob)) {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'This is not a purge request.',
                ];
            }

            if ($lockedJob->purge_status !== 'requested') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' => 'Only requested purge operations can be rejected.',
                ];
            }

            $lockedJob->forceFill([
                'purge_status' => 'rejected',
                'status' => 'rejected',
                'purge_reason' => $validated['reason'],
                'metadata' => array_merge(
                    $lockedJob->metadata ?? [],
                    [
                        'purge_rejected_at' => now()->toISOString(),
                        'purge_rejected_by' => $user->id,
                        'purge_rejection_reason' => $validated['reason'],
                        'data_deleted' => false,
                    ]
                ),
            ])->save();

            if ($lockedJob->tenant_id) {
                $tenant = Tenant::find($lockedJob->tenant_id);

                if ($tenant) {
                    $this->writePurgeAudit(
                        $tenant,
                        $user->id,
                        'tenant.purge.rejected',
                        [
                            'request_id' => $lockedJob->id,
                            'reason' => $validated['reason'],
                            'data_deleted' => false,
                        ]
                    );
                }
            }

            return [
                'error' => false,
                'job' => $lockedJob->fresh(),
            ];
        });

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Purge request rejected. No tenant data was deleted.',
            'data' => $result['job']->load($this->relations()),
        ]);
    }

    /**
     * Reject a normal offboarding request.
     */
    public function reject(
        Request $request,
        TenantOffboardingJob $job
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:1000',
            ],
        ]);

        $result = DB::transaction(function () use (
            $request,
            $job,
            $validated
        ): array {
            $locked = TenantOffboardingJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isPurgeRequest($locked)) {
                return [
                    'conflict' => true,
                    'message' => 'Purge requests must use the dedicated purge rejection workflow.',
                ];
            }

            if (! in_array(
                $locked->status,
                ['pending', 'scheduled'],
                true
            )) {
                return [
                    'conflict' => true,
                    'message' => 'Only pending or scheduled requests can be rejected.',
                ];
            }

            $locked->update([
                'status' => 'rejected',
                'metadata' => array_merge(
                    $locked->metadata ?? [],
                    [
                        'rejected_at' => now()->toISOString(),
                        'rejected_by' => $request->user()->id,
                        'rejection_reason' => $validated['reason'],
                    ]
                ),
            ]);

            return [
                'conflict' => false,
                'job' => $locked->fresh(),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => $result['message'],
            ], 409);
        }

        return response()->json([
            'message' => 'Offboarding request rejected.',
            'data' => $result['job']->load($this->relations()),
        ]);
    }

    /**
     * Cancel a normal offboarding request.
     */
    public function cancel(
        Request $request,
        TenantOffboardingJob $job
    ): JsonResponse {
        $result = DB::transaction(function () use (
            $request,
            $job
        ): array {
            $locked = TenantOffboardingJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isPurgeRequest($locked)) {
                return [
                    'conflict' => true,
                    'message' => 'Purge requests must use the dedicated purge workflow.',
                ];
            }

            if (! in_array(
                $locked->status,
                ['pending', 'approved', 'scheduled'],
                true
            )) {
                return [
                    'conflict' => true,
                    'message' => 'Only pending, approved, or scheduled requests can be cancelled.',
                ];
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'metadata' => array_merge(
                    $locked->metadata ?? [],
                    [
                        'cancelled_by' => $request->user()->id,
                        'cancelled_at' => now()->toISOString(),
                    ]
                ),
            ]);

            return [
                'conflict' => false,
                'job' => $locked->fresh(),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => $result['message'],
            ], 409);
        }

        return response()->json([
            'message' => 'Offboarding request cancelled. Tenant data has not been deleted.',
            'data' => $result['job']->load($this->relations()),
        ]);
    }

    /**
     * Retry a failed normal offboarding request.
     */
    public function retry(
        Request $request,
        TenantOffboardingJob $job
    ): JsonResponse {
        $result = DB::transaction(function () use ($job): array {
            $locked = TenantOffboardingJob::query()
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isPurgeRequest($locked)) {
                return [
                    'conflict' => true,
                    'message' => 'Purge requests cannot use the normal offboarding retry endpoint.',
                ];
            }

            if ($locked->status !== 'failed') {
                return [
                    'conflict' => true,
                    'message' => 'Only failed offboarding requests can be retried.',
                ];
            }

            $locked->steps()
                ->where('status', '!=', 'completed')
                ->update([
                    'status' => 'pending',
                    'error_message' => null,
                    'started_at' => null,
                    'completed_at' => null,
                ]);

            $locked->update([
                'status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'cancelled_at' => null,
                'metadata' => array_merge(
                    $locked->metadata ?? [],
                    [
                        'retry_execution_pending_worker' => true,
                        'retry_requested_at' => now()->toISOString(),
                        'last_retry_error' => $locked->metadata['last_error'] ?? null,
                    ]
                ),
            ]);

            return [
                'conflict' => false,
                'job' => $locked->fresh(),
            ];
        });

        if ($result['conflict']) {
            return response()->json([
                'message' => $result['message'],
            ], 409);
        }

        ProcessTenantOffboardingJob::dispatch(
            $result['job']->id
        );

        return response()->json([
            'message' => 'Offboarding retry has been queued.',
            'data' => $result['job']->load($this->relations()),
        ]);
    }



    /**
     * Build a preliminary purge eligibility preview.
     *
     * This method never deletes data and does not certify legal or financial clearance.
     */
    private function buildPurgePreview(Tenant $tenant): array
    {
        $tenant->refresh();

        $tenantId = (int) $tenant->id;
        $archivedAt = $tenant->archived_at;
        $retentionUntil = $archivedAt
            ? $archivedAt->copy()->addDays(self::RETENTION_DAYS)
            : null;

        $isArchived = $tenant->status === 'archived';
        $legalHoldActive = $tenant->legal_hold_at !== null;

        $retentionComplete = $retentionUntil !== null
            && now()->greaterThanOrEqualTo($retentionUntil);

        $recordCounts = [];

        foreach (self::PURGE_COUNT_TABLES as $table) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumn($table, 'tenant_id')
            ) {
                $recordCounts[$table] = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->count();
            }
        }

        $dependentRecordCounts = [];

        if (
            Schema::hasTable('conversations')
            && Schema::hasTable('whatsapp_messages')
            && Schema::hasColumn('conversations', 'tenant_id')
            && Schema::hasColumn('whatsapp_messages', 'conversation_id')
        ) {
            $dependentRecordCounts['whatsapp_messages_via_conversations'] =
                DB::table('whatsapp_messages')
                ->join(
                    'conversations',
                    'conversations.id',
                    '=',
                    'whatsapp_messages.conversation_id'
                )
                ->where('conversations.tenant_id', $tenantId)
                ->count();
        }

        if (
            Schema::hasTable('contact_interactions')
            && Schema::hasTable('contacts')
            && Schema::hasColumn('contact_interactions', 'contact_id')
            && Schema::hasColumn('contacts', 'tenant_id')
        ) {
            $dependentRecordCounts['contact_interactions_via_contacts'] =
                DB::table('contact_interactions')
                ->join(
                    'contacts',
                    'contacts.id',
                    '=',
                    'contact_interactions.contact_id'
                )
                ->where('contacts.tenant_id', $tenantId)
                ->count();
        }

        if (
            Schema::hasTable('contact_interactions')
            && Schema::hasTable('conversations')
            && Schema::hasColumn('contact_interactions', 'conversation_id')
            && Schema::hasColumn('conversations', 'tenant_id')
        ) {
            $dependentRecordCounts['contact_interactions_via_conversations'] =
                DB::table('contact_interactions')
                ->join(
                    'conversations',
                    'conversations.id',
                    '=',
                    'contact_interactions.conversation_id'
                )
                ->where('conversations.tenant_id', $tenantId)
                ->count();
        }

        if (
            Schema::hasTable('lead_notes')
            && Schema::hasTable('leads')
            && Schema::hasColumn('lead_notes', 'lead_id')
            && Schema::hasColumn('leads', 'tenant_id')
        ) {
            $dependentRecordCounts['lead_notes_via_leads'] =
                DB::table('lead_notes')
                ->join(
                    'leads',
                    'leads.id',
                    '=',
                    'lead_notes.lead_id'
                )
                ->where('leads.tenant_id', $tenantId)
                ->count();
        }

        if (
            Schema::hasTable('tenant_offboarding_steps')
            && Schema::hasTable('tenant_offboarding_jobs')
            && Schema::hasColumn('tenant_offboarding_jobs', 'tenant_id')
            && Schema::hasColumn(
                'tenant_offboarding_steps',
                'offboarding_job_id'
            )
        ) {
            $dependentRecordCounts['offboarding_steps_via_jobs'] =
                DB::table('tenant_offboarding_steps')
                ->join(
                    'tenant_offboarding_jobs',
                    'tenant_offboarding_jobs.id',
                    '=',
                    'tenant_offboarding_steps.offboarding_job_id'
                )
                ->where('tenant_offboarding_jobs.tenant_id', $tenantId)
                ->count();
        }

        if (
            Schema::hasTable('tenant_bulk_operations')
            && Schema::hasTable('tenant_bulk_operation_items')
            && Schema::hasColumn(
                'tenant_bulk_operation_items',
                'tenant_id'
            )
            && Schema::hasColumn(
                'tenant_bulk_operation_items',
                'bulk_operation_id'
            )
        ) {
            $dependentRecordCounts['bulk_operations_via_items'] =
                DB::table('tenant_bulk_operations')
                ->join(
                    'tenant_bulk_operation_items',
                    'tenant_bulk_operation_items.bulk_operation_id',
                    '=',
                    'tenant_bulk_operations.id'
                )
                ->where(
                    'tenant_bulk_operation_items.tenant_id',
                    $tenantId
                )
                ->distinct()
                ->count('tenant_bulk_operations.id');
        }

        $retentionPolicies = [
            'tenant_health_checks' =>
            'Health-check history retention must be decided.',
            'tenant_internal_notes' =>
            'Internal-note retention must be decided.',
            'tenant_exports' =>
            'Export metadata and associated files require review.',
            'tenant_subscription_events' =>
            'Subscription and billing history retention requires review.',
            'tenant_status_history' =>
            'Tenant status history retention requires review.',
            'tenant_activity_logs' =>
            'Audit-log retention must be decided.',
            'tenant_bulk_operation_items' =>
            'Bulk-operation item history requires review.',
            'tenant_offboarding_jobs' =>
            'Offboarding request history must be preserved or handled explicitly.',
        ];

        $retentionReview = [];

        foreach ($retentionPolicies as $table => $reason) {
            $count = $recordCounts[$table] ?? 0;

            $retentionReview[$table] = [
                'records' => $count,
                'decision_required' => $count > 0,
                'reason' => $reason,
            ];
        }

        $retentionReview['tenant_offboarding_steps'] = [
            'records' => $dependentRecordCounts['offboarding_steps_via_jobs'] ?? 0,
            'decision_required' => ($dependentRecordCounts['offboarding_steps_via_jobs'] ?? 0) > 0,
            'reason' =>
            'Offboarding steps are restricted by their parent job relationship.',
        ];

        $retentionReview['tenant_bulk_operations'] = [
            'records' => $dependentRecordCounts['bulk_operations_via_items'] ?? 0,
            'decision_required' => ($dependentRecordCounts['bulk_operations_via_items'] ?? 0) > 0,
            'reason' =>
            'Bulk-operation history must be reviewed before deleting related items.',
        ];

        $blockers = [];

        if (! $isArchived) {
            $blockers[] = 'Tenant must be archived before purge.';
        }

        if (! $archivedAt) {
            $blockers[] = 'Archive timestamp is missing.';
        } elseif (! $retentionComplete) {
            $blockers[] = 'The 90-day retention period has not completed.';
        }

        if ($legalHoldActive) {
            $blockers[] = 'An active legal hold prevents purge.';
        }

        foreach ($retentionReview as $table => $review) {
            if ($review['decision_required']) {
                $blockers[] = $table . ': ' . $review['reason'];
            }
        }

        // Keep this false until all dependency, financial, file, and retention checks are implemented.
        $dependencyChecksComplete = false;

        $blockers[] =
            'Comprehensive dependency, financial-obligation, external-file, and audit-retention checks remain incomplete.';

        return [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'status' => $tenant->status,
                'archived_at' => $archivedAt?->toISOString(),
                'legal_hold_at' => $tenant->legal_hold_at?->toISOString(),
            ],
            'retention' => [
                'retention_days' => self::RETENTION_DAYS,
                'retention_until' => $retentionUntil?->toISOString(),
                'completed' => $retentionComplete,
            ],
            'legal_hold_active' => $legalHoldActive,
            'record_counts' => $recordCounts,
            'dependent_record_counts' => $dependentRecordCounts,
            'retention_review' => $retentionReview,
            'dependency_checks_complete' => $dependencyChecksComplete,
            'blockers' => array_values(array_unique($blockers)),
            'eligible_for_purge' => false,
            'data_deleted' => false,
        ];
    }



    /**
     * Write an audit record without deleting historical evidence.
     */
    private function writePurgeAudit(
        Tenant $tenant,
        int $actorId,
        string $eventType,
        array $values
    ): void {
        TenantActivityLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actorId,
            'actor_type' => 'user',
            'event_type' => $eventType,
            'description' => str_replace('.', ' ', ucfirst($eventType)) . '.',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'old_values' => [],
            'new_values' => $values,
        ]);
    }
}
