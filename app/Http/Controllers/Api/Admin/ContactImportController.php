<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contact\ImportContactsRequest;
use App\Imports\ContactsImport;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ContactImportController extends Controller
{
    public function __invoke(
        ImportContactsRequest $request,
        ContactService $contactService
    ): JsonResponse {
        $data = $request->validated();
        $tenantId = (int) ($data['tenant_id'] ?? $data['business_id']);

        $import = new ContactsImport(
            $tenantId,
            $contactService
        );

        try {
            Excel::import($import, $request->file('file'));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to read the uploaded file. Please verify its format and headings.',
            ], 422);
        }

        return response()->json([
            'message' => 'Contact import processed.',
            'report' => $import->report(),
        ]);
    }
}
