<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantBranding;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TenantBrandingService
{
    /**
     * Max file size in bytes: 2MB (2048 KB).
     */
    public const MAX_FILE_SIZE = 2097152;

    /**
     * Supported image formats. SVG is strictly excluded for security MVP.
     */
    public const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    /**
     * Get or initialize branding for a tenant.
     */
    public function getBranding(Tenant $tenant): TenantBranding
    {
        return $tenant->branding ?? TenantBranding::firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'brand_name' => $tenant->company_name ?? $tenant->name,
                'primary_color' => '#128C7E',
                'secondary_color' => '#25D366',
                'support_email' => $tenant->email,
            ]
        );
    }

    /**
     * Update tenant branding settings.
     */
    public function updateBranding(Tenant $tenant, array $data, ?User $actor = null, ?Request $request = null): TenantBranding
    {
        $branding = $this->getBranding($tenant);
        $oldValues = $branding->only(['brand_name', 'primary_color', 'secondary_color', 'support_email']);

        $fields = array_intersect_key($data, array_flip([
            'brand_name',
            'primary_color',
            'secondary_color',
            'support_email',
        ]));

        $branding->fill($fields);
        $branding->save();

        if ($actor) {
            $this->logActivity(
                $tenant,
                $actor,
                'tenant.branding_updated',
                "Updated branding configuration for tenant {$tenant->id}",
                $oldValues,
                $branding->only(array_keys($fields)),
                $request
            );
        }

        return $branding;
    }

    /**
     * Validate, re-encode, and safely store asset (logo or favicon).
     */
    public function storeAsset(Tenant $tenant, UploadedFile $file, string $type, ?User $actor = null, ?Request $request = null): array
    {
        if (! in_array($type, ['logo', 'favicon'], true)) {
            throw new InvalidArgumentException("Invalid asset type: {$type}. Must be 'logo' or 'favicon'.");
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException("File size exceeds maximum allowed limit of 2MB.");
        }

        // Validate MIME type
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Invalid image format. Only PNG, JPEG, and WebP are supported.");
        }

        // Deep inspect file content to prevent SVG or script polyglots
        $content = file_get_contents($file->getRealPath());
        if (stripos($content, '<svg') !== false || stripos($content, '<?xml') !== false || stripos($content, '<script') !== false) {
            throw new InvalidArgumentException("Uploaded file contains disallowed markup.");
        }

        // Safely decode image using GD to verify real image data
        $image = @imagecreatefromstring($content);
        if ($image === false) {
            throw new InvalidArgumentException("Unable to decode image. File may be corrupted or malformed.");
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > 4000 || $height > 4000 || $width < 8 || $height < 8) {
            imagedestroy($image);
            throw new InvalidArgumentException("Image dimensions are invalid or exceed 4000x4000 limit.");
        }

        // Prepare destination directory & random server-controlled filename
        $directory = "tenant-branding/{$tenant->id}";
        $filename = "{$type}_" . Str::random(24) . '.png';
        $fullPath = "{$directory}/{$filename}";

        // Preserve PNG transparency
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Re-encode safely to PNG buffer
        ob_start();
        imagepng($image);
        $cleanImageData = ob_get_clean();
        imagedestroy($image);

        // Clean up previous asset if it exists
        $branding = $this->getBranding($tenant);
        $previousPath = ($type === 'logo') ? $branding->logo_path : $branding->favicon_path;

        Storage::disk('public')->put($fullPath, $cleanImageData);

        if ($previousPath && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        // Update model
        if ($type === 'logo') {
            $branding->logo_path = $fullPath;
        } else {
            $branding->favicon_path = $fullPath;
        }
        $branding->save();

        if ($actor) {
            $this->logActivity(
                $tenant,
                $actor,
                "tenant.branding_{$type}_uploaded",
                "Uploaded new {$type} asset for tenant {$tenant->id}",
                ['previous' => $previousPath],
                ['stored_path' => $fullPath],
                $request
            );
        }

        return [
            'type' => $type,
            'path' => $fullPath,
            'url' => Storage::disk('public')->url($fullPath),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Generate preview configuration.
     */
    public function getPreview(Tenant $tenant, TenantBranding $branding): array
    {
        return [
            'tenant_id' => $tenant->id,
            'brand_name' => $branding->brand_name ?? $tenant->company_name ?? $tenant->name,
            'logo_url' => $branding->logo_url,
            'favicon_url' => $branding->favicon_url,
            'primary_color' => $branding->primary_color ?? '#128C7E',
            'secondary_color' => $branding->secondary_color ?? '#25D366',
            'support_email' => $branding->support_email ?? $tenant->email,
            'preview_components' => [
                'login_page' => [
                    'brand_title' => $branding->brand_name ?? $tenant->company_name ?? $tenant->name,
                    'logo_url' => $branding->logo_url,
                    'button_color' => $branding->primary_color ?? '#128C7E',
                    'background_accent' => $branding->secondary_color ?? '#25D366',
                ],
                'sidebar' => [
                    'header_title' => $branding->brand_name ?? $tenant->company_name ?? $tenant->name,
                    'header_logo_url' => $branding->logo_url,
                    'active_item_color' => $branding->primary_color ?? '#128C7E',
                ],
                'browser_tab' => [
                    'title' => $branding->brand_name ?? $tenant->company_name ?? $tenant->name,
                    'favicon_url' => $branding->favicon_url,
                ],
            ],
        ];
    }

    /**
     * Record activity log without exposing sensitive tokens.
     */
    public function logActivity(
        Tenant $tenant,
        User $actor,
        string $eventType,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): void {
        try {
            TenantActivityLog::create([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $actor->id,
                'actor_type' => 'user',
                'event_type' => $eventType,
                'description' => $description,
                'subject_type' => Tenant::class,
                'subject_id' => $tenant->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->header('User-Agent'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
