<?php

namespace App\Http\Resources;

use App\Domain\Tenancy\Models\TenantBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantBranding */
class BrandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->display_name,
            'has_logo' => $this->logo_path !== null,
            'logo_version' => $this->logo_path ? substr(sha1($this->logo_path), 0, 10) : null,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'accent_color' => $this->accent_color,
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
            'website' => $this->website,
            'address' => $this->address,
            'document_footer' => $this->document_footer,
        ];
    }
}
