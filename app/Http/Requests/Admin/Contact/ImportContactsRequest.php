<?php

namespace App\Http\Requests\Admin\Contact;

use Illuminate\Foundation\Http\FormRequest;

class ImportContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'tenant_id.required_without' => 'Tenant ID is required.',
            'tenant_id.exists' => 'Selected tenant does not exist.',
            'business_id.required_without' => 'Business ID is required.',
            'business_id.exists' => 'Selected business does not exist.',
            'file.required' => 'Please upload a CSV or Excel file.',
            'file.mimes' => 'Only CSV and XLSX files are supported.',
            'file.max' => 'The file must not exceed 10 MB.',
        ];
    }
}
