<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactExportController extends Controller
{
    public function export(Request $request)
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
            'format' => [
                'nullable',
                'in:csv,xlsx',
            ],
        ]);

        $tenantId = (int) $tenantId;
        $format = $data['format'] ?? 'csv';

        $contacts = Contact::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get([
                'name',
                'phone',
                'email',
                'company',
                'tags',
            ]);

        $rows = $contacts->map(function (Contact $contact) {
            return [
                'name' => $contact->name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'company' => $contact->company,
                'tags' => implode(',', $contact->tags ?? []),
            ];
        });

        $export = new class($rows) implements
            \Maatwebsite\Excel\Concerns\FromCollection,
            \Maatwebsite\Excel\Concerns\WithHeadings
        {
            public function __construct(
                private readonly \Illuminate\Support\Collection $rows
            ) {}

            public function collection(): \Illuminate\Support\Collection
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return [
                    'name',
                    'phone',
                    'email',
                    'company',
                    'tags',
                ];
            }
        };

        $extension = $format === 'xlsx' ? 'xlsx' : 'csv';

        return Excel::download(
            $export,
            "contacts-business-{$businessId}.{$extension}"
        );
    }

    public function template(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' =>
            'attachment; filename="contacts-import-example.csv"',
        ];

        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility.
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'name',
                'phone',
                'email',
                'company',
                'tags',
            ]);

            fputcsv($output, [
                'Rahul Sharma',
                '919876543210',
                'rahul@example.com',
                'ABC Traders',
                'wholesale',
            ]);

            fputcsv($output, [
                'Amit Kumar',
                '919812345678',
                'amit@example.com',
                'XYZ Store',
                'retail',
            ]);

            fclose($output);
        }, 'contacts-import-example.csv', $headers);
    }
}
