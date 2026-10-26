<?php

namespace App\Jobs;

use App\Exports\TenantRowsExport;
use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Throwable;

class ProcessTenantExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $exportId
    ) {}

    public function handle(): void
    {
        $export = TenantExport::find($this->exportId);

        if (! $export || $export->status === 'completed') {
            return;
        }

        $export->update([
            'status' => 'processing',
            'started_at' => now(),
            'failure_reason' => null,
        ]);

        try {
            $savedFilters = $export->filters ?? [];
            $filters = $savedFilters['filters'] ?? [];
            $columns = $savedFilters['columns'] ?? [
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

            $allowedColumns = [
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

            // Revalidate stored column names before using them.
            $columns = array_values(array_intersect(
                $columns,
                $allowedColumns
            ));

            if (empty($columns)) {
                throw new \RuntimeException(
                    'No valid export columns were selected.'
                );
            }

            $query = Tenant::query();

            if (! empty($filters['search'])) {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('company_name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            $recordCount = (clone $query)->count();

            $rows = $query
                ->orderBy('id')
                ->get($columns)
                ->map(function ($tenant) use ($columns) {
                    return array_map(
                        fn($column) => $tenant->getAttribute($column),
                        $columns
                    );
                })
                ->all();

            $disk = 'local';
            $directory = 'tenant-exports';
            $filename = 'tenant-export-' . $export->id . '-' . now()->format('YmdHis');

            if ($export->format === 'json') {
                $path = "{$directory}/{$filename}.json";

                $contents = json_encode(
                    [
                        'columns' => $columns,
                        'data' => array_map(
                            fn($row) => array_combine($columns, $row),
                            $rows
                        ),
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );

                if ($contents === false) {
                    throw new \RuntimeException('Could not encode export JSON.');
                }

                Storage::disk($disk)->put($path, $contents);
            } elseif (in_array($export->format, ['csv', 'xlsx'], true)) {
                $path = "{$directory}/{$filename}." . $export->format;

                $writerType = $export->format === 'xlsx'
                    ? ExcelFormat::XLSX
                    : ExcelFormat::CSV;

                $stored = Excel::store(
                    new TenantRowsExport($rows, $columns),
                    $path,
                    $disk,
                    $writerType
                );

                if (! $stored) {
                    throw new \RuntimeException('Could not save the export file.');
                }
            } else {
                throw new \RuntimeException('Unsupported export format.');
            }

            $fileSize = Storage::disk($disk)->size($path);

            $export->update([
                'status' => 'completed',
                'file_disk' => $disk,
                'file_path' => $path,
                'file_size_bytes' => $fileSize,
                'record_count' => $recordCount,
                'completed_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
        } catch (Throwable $exception) {
            Log::error('Tenant export failed.', [
                'export_id' => $this->exportId,
                'exception' => $exception,
            ]);

            $export->update([
                'status' => 'failed',
                'failure_reason' => 'Export generation failed. Please retry.',
                'completed_at' => now(),
            ]);

            // Do not rethrow here: the export is marked failed.
        }
    }
}
