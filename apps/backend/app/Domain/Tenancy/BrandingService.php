<?php

namespace App\Domain\Tenancy;

use App\Domain\AuditLog\Auditor;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Shared\Storage\FileStorage;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class BrandingService
{
    /** Raster formats only: SVG can carry scripts. */
    public const LOGO_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private readonly FileStorage $storage,
        private readonly Auditor $auditor,
    ) {}

    public function for(Tenant $tenant): TenantBranding
    {
        $branding = TenantBranding::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            TenantBranding::defaultsFor($tenant),
        );
        // Defaults materialised on first access are not a client-visible "create" (no 201).
        $branding->wasRecentlyCreated = false;

        return $branding;
    }

    /** @param array<string, string|null> $data */
    public function update(Tenant $tenant, array $data): TenantBranding
    {
        $branding = $this->for($tenant);
        $branding->fill($data)->save();
        [$old, $new] = Auditor::diff($branding);
        if ($new) {
            $this->auditor->record('branding.updated', $branding, $old, $new, tenantId: $tenant->id);
        }

        return $branding;
    }

    public function uploadLogo(Tenant $tenant, UploadedFile $file): TenantBranding
    {
        $bytes = $file->getContent();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, self::LOGO_MIME_TYPES, true) || @getimagesizefromstring($bytes) === false) {
            throw BusinessRuleException::make('INVALID_LOGO', __('errors.invalid_logo'), 422);
        }

        $branding = $this->for($tenant);
        $previous = $branding->logo_path;
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime];
        $path = sprintf('%d/logo-%s.%s', $tenant->id, Str::lower(Str::random(10)), $extension);

        $this->storage->put(config('filestorage.buckets.branding'), $path, $bytes, $mime);
        $branding->forceFill(['logo_path' => $path])->save();

        if ($previous) {
            $this->storage->delete(config('filestorage.buckets.branding'), $previous);
        }

        $this->auditor->record('branding.logo_updated', $branding, ['logo_path' => $previous], ['logo_path' => $path], tenantId: $tenant->id);

        return $branding;
    }

    /** @return array{bytes: string, mime: string}|null */
    public function logo(TenantBranding $branding): ?array
    {
        if (! $branding->logo_path) {
            return null;
        }

        $bytes = $this->storage->get(config('filestorage.buckets.branding'), $branding->logo_path);

        return ['bytes' => $bytes, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'];
    }
}
