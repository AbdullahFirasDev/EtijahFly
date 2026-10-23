<?php

namespace App\Domain\Identity\Permissions;

/**
 * Single source of truth for permissions and system roles.
 * Platform permissions are prefixed "platform." and can never be granted to tenant roles.
 * Labels live in lang/{ar,en}/permissions.php.
 */
final class PermissionCatalog
{
    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    /** @var array<string, string> permission => group */
    public const TENANT_PERMISSIONS = [
        'flights.search' => 'bookings',
        'bookings.view' => 'bookings',          // bookings the user created
        'bookings.view_all' => 'bookings',      // every booking of the company
        'bookings.create' => 'bookings',
        'bookings.issue' => 'bookings',
        'bookings.cancel' => 'bookings',
        'bookings.refund' => 'bookings',
        'travelers.manage' => 'bookings',
        'documents.view' => 'bookings',
        'finance.view' => 'finance',
        'payments.submit' => 'finance',
        'invoices.view' => 'finance',
        'commissions.view' => 'finance',
        'reports.view' => 'reports',
        'team.view' => 'team',
        'team.manage' => 'team',
        'roles.manage' => 'team',
        'branding.manage' => 'settings',
    ];

    /** @var array<string, string> permission => group */
    public const PLATFORM_PERMISSIONS = [
        'platform.dashboard' => 'overview',
        'platform.tenants.view' => 'tenants',
        'platform.tenants.manage' => 'tenants',
        'platform.users.manage' => 'users',
        'platform.providers.view' => 'providers',
        'platform.providers.manage' => 'providers',
        'platform.pricing.view' => 'pricing',
        'platform.pricing.manage' => 'pricing',
        'platform.exchange_rates.manage' => 'pricing',
        'platform.credit.manage' => 'finance',
        'platform.finance.view' => 'finance',
        'platform.payments.review' => 'finance',
        'platform.adjustments.request' => 'finance',
        'platform.adjustments.approve' => 'finance',
        'platform.bookings.view' => 'bookings',
        'platform.bookings.manage' => 'bookings',
        'platform.reports.view' => 'reports',
        'platform.audit.view' => 'system',
        'platform.system.view' => 'system',
    ];

    /**
     * System roles. '*' = every permission of the role's scope.
     *
     * @var array<string, array{scope: string, permissions: list<string>|'*'}>
     */
    public const SYSTEM_ROLES = [
        'super_admin' => ['scope' => self::SCOPE_PLATFORM, 'permissions' => '*'],
        'platform_staff' => ['scope' => self::SCOPE_PLATFORM, 'permissions' => [
            'platform.dashboard', 'platform.tenants.view', 'platform.providers.view', 'platform.pricing.view',
            'platform.finance.view', 'platform.adjustments.request', 'platform.bookings.view',
            'platform.bookings.manage', 'platform.reports.view', 'platform.audit.view', 'platform.system.view',
        ]],
        'platform_finance' => ['scope' => self::SCOPE_PLATFORM, 'permissions' => [
            'platform.dashboard', 'platform.tenants.view', 'platform.finance.view', 'platform.payments.review',
            'platform.adjustments.request', 'platform.adjustments.approve', 'platform.credit.manage',
            'platform.exchange_rates.manage', 'platform.pricing.view', 'platform.bookings.view', 'platform.reports.view',
        ]],
        'company_owner' => ['scope' => self::SCOPE_TENANT, 'permissions' => '*'],
        'booking_agent' => ['scope' => self::SCOPE_TENANT, 'permissions' => [
            'flights.search', 'bookings.view', 'bookings.create', 'bookings.issue', 'bookings.cancel',
            'bookings.refund', 'travelers.manage', 'documents.view',
        ]],
        'accountant' => ['scope' => self::SCOPE_TENANT, 'permissions' => [
            'bookings.view', 'bookings.view_all', 'documents.view', 'finance.view', 'payments.submit',
            'invoices.view', 'commissions.view', 'reports.view',
        ]],
    ];

    /** @return array<string, string> */
    public static function forScope(string $scope): array
    {
        return $scope === self::SCOPE_PLATFORM ? self::PLATFORM_PERMISSIONS : self::TENANT_PERMISSIONS;
    }

    /** @return list<string> */
    public static function permissionsForRole(string $role): array
    {
        $definition = self::SYSTEM_ROLES[$role];

        return $definition['permissions'] === '*'
            ? array_keys(self::forScope($definition['scope']))
            : $definition['permissions'];
    }

    public static function scopeOf(string $permission): ?string
    {
        return match (true) {
            isset(self::PLATFORM_PERMISSIONS[$permission]) => self::SCOPE_PLATFORM,
            isset(self::TENANT_PERMISSIONS[$permission]) => self::SCOPE_TENANT,
            default => null,
        };
    }
}
