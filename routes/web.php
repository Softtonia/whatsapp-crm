<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['tenant.domain'])->group(function () {
    Route::get('/', function () {
        if ($tenant = request()->attributes->get('tenant')) {
            $branding = $tenant->branding;
            return response()->json([
                'status' => 'active',
                'tenant_id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'brand_name' => $branding?->brand_name ?? $tenant->company_name,
                'custom_domain' => request()->attributes->get('custom_domain')?->hostname,
            ]);
        }

        return view('welcome');
    });
});
