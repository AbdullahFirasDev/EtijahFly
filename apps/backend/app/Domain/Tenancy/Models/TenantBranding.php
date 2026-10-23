<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $display_name
 * @property string|null $logo_path
 * @property string $primary_color
 * @property string $secondary_color
 * @property string $accent_color
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property string|null $website
 * @property string|null $address
 * @property string|null $document_footer
 */
class TenantBranding extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'display_name', 'logo_path', 'primary_color', 'secondary_color', 'accent_color',
        'support_email', 'support_phone', 'website', 'address', 'document_footer',
    ];

    /** @return array<string, string|null> */
    public static function defaultsFor(Tenant $tenant): array
    {
        return [
            'display_name' => $tenant->name,
            'primary_color' => '#0F4C81',
            'secondary_color' => '#1B998B',
            'accent_color' => '#F4A259',
            'support_email' => $tenant->contact_email,
            'support_phone' => $tenant->contact_phone,
            'address' => $tenant->address,
        ];
    }
}
