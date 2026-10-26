<?php

namespace App\Imports;

use App\Models\Contact;
use App\Services\ContactService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\ToCollection;

class ContactsImport implements ToCollection
{
    public int $totalRows = 0;
    public int $imported = 0;
    public int $duplicates = 0;
    public int $failed = 0;

    public array $errors = [];

    private array $seenPhones = [];

    public function __construct(
        private readonly int $tenantId,
        private readonly ContactService $contactService,
    ) {}

    public function collection(Collection $rows): void
    {
        // Remove completely empty rows, including leading blank rows.
        $rows = $rows
            ->filter(function ($row) {
                return collect($row)->contains(function ($value) {
                    return $value !== null
                        && trim((string) $value) !== '';
                });
            })
            ->values();

        if ($rows->isEmpty()) {
            $this->failed++;

            $this->errors[] = [
                'row' => 1,
                'reason' => ['The uploaded file is empty.'],
            ];

            return;
        }

        // Normalize heading names and remove UTF-8 BOM.
        $headings = $rows->first()
            ->map(function ($value) {
                $value = (string) ($value ?? '');

                // Remove UTF-8 BOM and Unicode BOM.
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
                $value = preg_replace('/^\x{FEFF}/u', '', $value);

                // Remove surrounding quotes and whitespace.
                return strtolower(trim($value, " \t\n\r\0\x0B\""));
            })
            ->values()
            ->all();

        // Required CSV columns.
        foreach (['name', 'phone'] as $requiredHeading) {
            if (!in_array($requiredHeading, $headings, true)) {
                $this->failed++;

                $this->errors[] = [
                    'row' => 1,
                    'reason' => [
                        "Missing required CSV column: {$requiredHeading}.",
                    ],
                ];

                return;
            }
        }

        // Process rows after the heading row.
        foreach ($rows->skip(1) as $index => $row) {
            $values = $row->values()->all();

            // Ignore completely empty rows.
            if (count(array_filter(
                $values,
                fn ($value) => $value !== null
                    && trim((string) $value) !== ''
            )) === 0) {
                continue;
            }

            $this->totalRows++;

            // CSV row number (including the heading row).
            $rowNumber = $index + 2;

            // Map each value to its heading.
            $values = array_pad($values, count($headings), null);
            $data = [];

            foreach ($headings as $columnIndex => $heading) {
                if ($heading !== '') {
                    $data[$heading] = $values[$columnIndex] ?? null;
                }
            }

            $name = trim((string) ($data['name'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));
            $company = trim((string) ($data['company'] ?? ''));
            $tagsValue = trim((string) ($data['tags'] ?? ''));

            $normalizedPhone = $this->contactService
                ->normalizePhone($phone);

            // Validate contact data.
            $validator = Validator::make([
                'name' => $name,
                'phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'company' => $company !== '' ? $company : null,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'string', 'max:30'],
                'email' => ['nullable', 'email', 'max:255'],
                'company' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails() || $normalizedPhone === '') {
                $this->failed++;

                $this->errors[] = [
                    'row' => $rowNumber,
                    'reason' => $validator->errors()->all()
                        ?: ['Phone number must contain digits.'],
                ];

                continue;
            }

            // Detect duplicate phone numbers within this file.
            if (isset($this->seenPhones[$normalizedPhone])) {
                $this->duplicates++;

                $this->errors[] = [
                    'row' => $rowNumber,
                    'reason' => [
                        'Duplicate phone within uploaded file.',
                    ],
                ];

                continue;
            }

            $this->seenPhones[$normalizedPhone] = true;

            // Detect contacts already saved for this tenant.
            $exists = Contact::query()
                ->where('tenant_id', $this->tenantId)
                ->where('normalized_phone', $normalizedPhone)
                ->exists();

            if ($exists) {
                $this->duplicates++;

                $this->errors[] = [
                    'row' => $rowNumber,
                    'reason' => [
                        'Contact with this phone already exists.',
                    ],
                ];

                continue;
            }

            // Convert comma-separated tags into an array.
            // Example: "wholesale,new" => ["wholesale", "new"]
            $tags = $tagsValue === ''
                ? []
                : array_values(array_unique(array_filter(
                    array_map('trim', explode(',', $tagsValue)),
                    fn ($tag) => $tag !== ''
                )));

            try {
                $this->contactService->saveContact(
                    $this->tenantId,
                    [
                        'name' => $name,
                        'phone' => $phone,
                        'email' => $email !== '' ? $email : null,
                        'company' => $company !== '' ? $company : null,
                        'source' => 'import',
                        'tags' => $tags,
                        'custom_fields' => [],
                    ]
                );

                $this->imported++;
            } catch (InvalidArgumentException $exception) {
                $this->duplicates++;

                $this->errors[] = [
                    'row' => $rowNumber,
                    'reason' => [$exception->getMessage()],
                ];
            } catch (ValidationException $exception) {
                $this->failed++;

                $this->errors[] = [
                    'row' => $rowNumber,
                    'reason' => $exception->validator->errors()->all(),
                ];
            }
        }
    }

    public function report(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'imported' => $this->imported,
            'duplicates' => $this->duplicates,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}
