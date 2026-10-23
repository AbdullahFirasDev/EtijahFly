<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Permissions\PermissionCatalog;
use App\Domain\Shared\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property bool $is_platform
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $locale
 * @property UserStatus $status
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $password_changed_at
 * @property-read Collection<int, Role> $roles
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Spatie permissions are registered on the web guard; API requests use Sanctum tokens. */
    protected string $guard_name = 'web';

    protected $fillable = [
        'tenant_id', 'is_platform', 'name', 'email', 'phone', 'locale', 'status', 'password',
        'password_changed_at', 'email_verified_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_platform' => 'boolean',
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPlatformUser(): bool
    {
        return $this->is_platform && $this->tenant_id === null;
    }

    public function portal(): string
    {
        return $this->isPlatformUser() ? 'admin' : 'partner';
    }

    public function permissionScope(): string
    {
        return $this->isPlatformUser() ? PermissionCatalog::SCOPE_PLATFORM : PermissionCatalog::SCOPE_TENANT;
    }

    /** Permission names, restricted to the user's scope (defence in depth against mis-assigned roles). */
    public function scopedPermissionNames(): array
    {
        $allowed = PermissionCatalog::forScope($this->permissionScope());

        return $this->getAllPermissions()
            ->pluck('name')
            ->filter(fn (string $name) => isset($allowed[$name]))
            ->values()
            ->all();
    }

    public function sendPasswordResetNotification($token): void
    {
        $base = config('platform.portals.'.$this->portal());
        $url = rtrim($base, '/').'/reset-password?token='.urlencode($token).'&email='.urlencode($this->email);

        $notification = new ResetPassword($token);
        $notification->createUrlUsing(fn () => $url);

        $this->notify($notification);
    }
}
