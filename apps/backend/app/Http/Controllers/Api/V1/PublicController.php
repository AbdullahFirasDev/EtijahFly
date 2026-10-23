<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\BrandingService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Unauthenticated endpoints: white-label branding for login pages and a liveness probe. */
class PublicController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    /**
     * Branding for a partner portal host (custom domain) or tenant slug.
     *
     * @unauthenticated
     */
    public function branding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:63'],
        ]);

        $tenant = $this->resolveTenant($data['host'] ?? null, $data['slug'] ?? null);
        if (! $tenant) {
            return response()->json(['tenant' => null, 'branding' => null, 'platform' => config('platform.name')]);
        }

        return response()->json([
            'tenant' => ['slug' => $tenant->slug, 'name' => $tenant->name],
            'branding' => new BrandingResource($this->branding->for($tenant)),
            'platform' => config('platform.name'),
        ]);
    }

    /**
     * Tenant logo (raster only). Logos are public brand assets, served through the API so
     * the storage bucket itself stays private.
     *
     * @unauthenticated
     */
    public function logo(string $slug): Response
    {
        $tenant = Tenant::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
        $logo = $this->branding->logo($this->branding->for($tenant));
        abort_if($logo === null, 404);

        return response($logo['bytes'], 200, [
            'Content-Type' => $logo['mime'],
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /** @unauthenticated */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
    }

    private function resolveTenant(?string $host, ?string $slug): ?Tenant
    {
        $query = Tenant::query()->where('status', 'active');

        if ($host) {
            $host = mb_strtolower(preg_replace('/:\d+$/', '', $host));
            if ($tenant = (clone $query)->where('custom_domain', $host)->first()) {
                return $tenant;
            }
        }

        return $slug ? $query->where('slug', $slug)->first() : null;
    }
}
