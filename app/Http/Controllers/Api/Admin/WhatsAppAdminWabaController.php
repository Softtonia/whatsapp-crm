<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantWhatsAppAccount;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppAdminWabaController extends Controller
{
    public function __construct(
        protected WhatsAppCloudApiService $whatsAppService
    ) {}

    /**
     * GET /api/auth/admin/whatsapp/accounts
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.read');

        $query = TenantWhatsAppAccount::with('tenant:id,company_name,slug,status');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->query('tenant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('waba_id', 'like', "%{$search}%")
                    ->orWhere('display_phone_number', 'like', "%{$search}%")
                    ->orWhere('phone_number_id', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $accounts = $query->orderByDesc('id')->paginate($perPage);

        $data = $accounts->getCollection()->map(function ($acc) {
            return [
                'id' => $acc->id,
                'tenant' => [
                    'id' => $acc->tenant?->id,
                    'name' => $acc->tenant?->company_name,
                    'status' => $acc->tenant?->status,
                ],
                'waba_id' => $acc->waba_id,
                'business_name' => $acc->business_name,
                'phone_number_id' => $acc->phone_number_id,
                'display_phone_number' => $this->maskPhoneNumber($acc->display_phone_number),
                'quality_rating' => $acc->quality_rating,
                'status' => $acc->status,
                'last_synced_at' => $acc->last_synced_at?->toIso8601String(),
                'last_sync_error' => $acc->last_sync_error,
                'created_at' => $acc->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * GET /api/auth/admin/whatsapp/accounts/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.read');

        $account = TenantWhatsAppAccount::with('tenant:id,company_name,slug,status')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $account->id,
                'tenant' => [
                    'id' => $account->tenant?->id,
                    'name' => $account->tenant?->company_name,
                    'status' => $account->tenant?->status,
                ],
                'waba_id' => $account->waba_id,
                'business_name' => $account->business_name,
                'phone_number_id' => $account->phone_number_id,
                'display_phone_number' => $this->maskPhoneNumber($account->display_phone_number),
                'quality_rating' => $account->quality_rating,
                'status' => $account->status,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'last_sync_error' => $account->last_sync_error,
                'created_at' => $account->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/auth/admin/whatsapp/accounts/{id}/sync
     */
    public function sync(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.sync');

        $account = TenantWhatsAppAccount::with('tenant')->findOrFail($id);

        try {
            $result = $this->whatsAppService->syncAccountDetails($account);
            $syncedAccount = $result['account'];

            return response()->json([
                'message' => 'WhatsApp account synchronized successfully with Meta Graph API.',
                'data' => [
                    'id' => $syncedAccount->id,
                    'business_name' => $syncedAccount->business_name,
                    'quality_rating' => $syncedAccount->quality_rating,
                    'last_synced_at' => $syncedAccount->last_synced_at?->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to synchronize WhatsApp account with Meta: ' . $e->getMessage(),
                'error' => 'sync_failed',
            ], 422);
        }
    }

    /**
     * GET /api/auth/admin/whatsapp/numbers
     */
    public function numbers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user(), 'whatsapp.read');

        $query = TenantWhatsAppAccount::with('tenant:id,company_name,status');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->query('tenant_id'));
        }

        $numbers = $query->orderByDesc('id')->get()->map(function ($acc) {
            return [
                'id' => $acc->id,
                'tenant' => [
                    'id' => $acc->tenant?->id,
                    'name' => $acc->tenant?->company_name,
                ],
                'phone_number_id' => $acc->phone_number_id,
                'waba_id' => $acc->waba_id,
                'display_phone_number' => $this->maskPhoneNumber($acc->display_phone_number),
                'quality_rating' => $acc->quality_rating,
                'status' => $acc->status,
                'last_synced_at' => $acc->last_synced_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $numbers]);
    }

    private function maskPhoneNumber(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $clean = preg_replace('/[^\d+]/', '', $phone);
        $len = strlen($clean);
        if ($len <= 5) {
            return $clean;
        }

        // Keep first 3 digits and last 2 digits, mask the rest with asterisks
        return substr($clean, 0, 3) . str_repeat('*', $len - 5) . substr($clean, -2);
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
