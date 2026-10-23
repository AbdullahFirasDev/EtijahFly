<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Tenancy\BrandingService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandingResource;
use Illuminate\Http\Request;

class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding) {}

    public function show(Request $request): BrandingResource
    {
        return new BrandingResource($this->branding->for($this->tenant($request)));
    }

    public function update(Request $request): BrandingResource
    {
        $this->authorize('branding.manage');
        $color = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'primary_color' => $color,
            'secondary_color' => $color,
            'accent_color' => $color,
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url:https,http', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'document_footer' => ['nullable', 'string', 'max:500'],
        ]);

        return new BrandingResource($this->branding->update($this->tenant($request), $data));
    }

    /** Upload a PNG/JPG/WEBP logo (max 1 MB). SVG is rejected. */
    public function uploadLogo(Request $request): BrandingResource
    {
        $this->authorize('branding.manage');
        $request->validate(['logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp', 'dimensions:max_width=2000,max_height=2000']]);

        return new BrandingResource($this->branding->uploadLogo($this->tenant($request), $request->file('logo')));
    }

    private function tenant(Request $request): Tenant
    {
        return Tenant::query()->findOrFail($request->user()->tenant_id);
    }
}
