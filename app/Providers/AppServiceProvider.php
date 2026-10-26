<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \App\Services\Contracts\DnsResolverInterface::class,
            \App\Services\Dns\SystemDnsResolver::class
        );

        $this->app->singleton(
            \App\Services\Contracts\TlsCertificateProviderInterface::class,
            \App\Services\Tls\CaddyTlsProvider::class
        );
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Route::model('tenant', \App\Models\Tenant::class);

        HeadingRowFormatter::extend('custom', function ($value) {
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);

            return strtolower(trim($value));
        });

        HeadingRowFormatter::default('custom');

        Gate::before(function ($user, string $ability) {
            if ($user->status && $user->status !== 'active') {
                return false;
            }

            return ($user->hasRole('platform_owner') || $user->hasRole('Super Admin') || $user->hasRole('super_admin')) ? true : null;
        });
    }
}
