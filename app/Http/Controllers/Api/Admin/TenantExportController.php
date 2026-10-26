<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTenantExportJob;
use App\Models\TenantExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TenantExportController extends Controller
{
    private const ALLOWED_COLUMNS = [
        'id',
        'company_name',
        'slug',
        'status',
        'email',
        'contact_number',
        'country',
        'currency',
        'timezone',
        'created_at',
    ];

    public function store(Request $request)
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(['csv', 'xlsx', 'json'])],

            'filters' => ['sometimes', 'array'],
            'filters.search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filters.status' => [
                'sometimes',
                'nullable',
                Rule::in(['active', 'inactive', 'suspended', 'archived']),
            ],

            'columns' => ['sometimes', 'array', 'min:1', 'max:10'],
            'columns.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(self::ALLOWED_COLUMNS),
            ],
        ]);

        $columns = $validated['columns'] ?? self::ALLOWED_COLUMNS;

        $export = TenantExport::create([
            'tenant_id' => null,
            'requested_by' => $request->user()->id,
            'export_type' => 'tenants',
            'format' => $validated['format'],
            'status' => 'pending',
            'filters' => [
                'filters' => $validated['filters'] ?? [],
                'columns' => $columns,
            ],
        ]);

        ProcessTenantExportJob::dispatch($export->id);

        return response()->json([
            'message' => 'Tenant export has been queued.',
            'data' => $export->fresh(),
        ], 202);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                Rule::in(['pending', 'processing', 'completed', 'failed']),
            ],
            'format' => [
                'sometimes',
                Rule::in(['csv', 'xlsx', 'json']),
            ],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TenantExport::query()
            ->where('export_type', 'tenants')
            ->latest('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['format'])) {
            $query->where('format', $validated['format']);
        }

        $exports = $query->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'message' => 'Tenant exports retrieved successfully.',
            'data' => $exports,
        ]);
    }

    public function show(TenantExport $export)
    {
        abort_unless($export->export_type === 'tenants', 404);

        return response()->json([
            'message' => 'Tenant export retrieved successfully.',
            'data' => $export,
        ]);
    }

    public function download(TenantExport $export)
    {
        abort_unless($export->export_type === 'tenants', 404);

        if ($export->status !== 'completed') {
            return response()->json([
                'message' => 'This export is not ready for download.',
                'status' => $export->status,
            ], 409);
        }

        if ($export->isExpired()) {
            return response()->json([
                'message' => 'This export has expired.',
            ], 410);
        }

        $disk = $export->file_disk ?: 'local';
        $path = $export->file_path;

        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return response()->json([
                'message' => 'Export file not found.',
            ], 404);
        }

        $export->update(['downloaded_at' => now()]);

        return Storage::disk($disk)->download(
            $path,
            basename($path)
        );
    }
}
