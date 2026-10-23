<?php

namespace App\Domain\Identity;

use App\Domain\AuditLog\Auditor;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token-based sessions. Tokens carry a portal ability (portal:partner | portal:admin), an
 * absolute expiry and are rejected after an idle period (see EnsureActiveSession). The
 * portals keep the token in an httpOnly cookie on their own server (BFF), never in JS.
 */
class AuthService
{
    public const PORTALS = ['partner', 'admin'];

    public function __construct(private readonly Auditor $auditor) {}

    public function login(string $email, string $password, string $portal, ?string $ip, ?string $userAgent): NewAccessToken
    {
        /** @var User|null $user */
        $user = User::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('email', mb_strtolower($email))->first();

        // Same response for unknown e-mail, wrong password and wrong portal: no account enumeration.
        if (! $user || ! Hash::check($password, $user->password) || $user->portal() !== $portal) {
            $this->auditor->record('auth.login_failed', $user, metadata: ['email' => mb_strtolower($email), 'portal' => $portal], tenantId: $user?->tenant_id, actorType: 'system');
            throw BusinessRuleException::make('INVALID_CREDENTIALS', __('auth.failed'), 422);
        }

        if (! $user->isActive()) {
            $this->auditor->record('auth.login_blocked', $user, metadata: ['reason' => 'user_disabled'], actor: $user);
            throw BusinessRuleException::make('ACCOUNT_DISABLED', __('errors.account_disabled'), 403);
        }

        if ($user->tenant_id && ! Tenant::query()->whereKey($user->tenant_id)->first()?->isActive()) {
            $this->auditor->record('auth.login_blocked', $user, metadata: ['reason' => 'tenant_suspended'], actor: $user);
            throw BusinessRuleException::make('TENANT_SUSPENDED', __('errors.tenant_suspended'), 403);
        }

        $this->pruneSessions($user);

        $token = $user->createToken(
            name: mb_substr($userAgent ?: 'session', 0, 120),
            abilities: ["portal:{$portal}"],
            expiresAt: now()->addMinutes((int) config('platform.auth.token_ttl_minutes')),
        );
        $token->accessToken->forceFill([
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            'last_used_at' => now(),
        ])->save();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();
        $this->auditor->record('auth.login', $user, metadata: ['portal' => $portal, 'token_id' => $token->accessToken->id], actor: $user);

        return $token;
    }

    public function logout(User $user, PersonalAccessToken $token): void
    {
        $token->delete();
        $this->auditor->record('auth.logout', $user, metadata: ['token_id' => $token->id], actor: $user);
    }

    public function revokeSession(User $user, int $tokenId): void
    {
        $deleted = $user->tokens()->whereKey($tokenId)->delete();
        if ($deleted === 0) {
            throw BusinessRuleException::make('SESSION_NOT_FOUND', __('errors.not_found'), 404);
        }
        $this->auditor->record('auth.session_revoked', $user, metadata: ['token_id' => $tokenId], actor: $user);
    }

    public function revokeAll(User $user, ?int $exceptTokenId = null): int
    {
        return $user->tokens()->when($exceptTokenId, fn ($q) => $q->whereKeyNot($exceptTokenId))->delete();
    }

    public function changePassword(User $user, string $current, string $new, int $currentTokenId): void
    {
        if (! Hash::check($current, $user->password)) {
            throw BusinessRuleException::make('INVALID_PASSWORD', __('errors.invalid_current_password'), 422);
        }

        $user->forceFill(['password' => $new, 'password_changed_at' => now()])->save();
        $revoked = $this->revokeAll($user, $currentTokenId);
        $this->auditor->record('auth.password_changed', $user, metadata: ['other_sessions_revoked' => $revoked], actor: $user);
    }

    private function pruneSessions(User $user): void
    {
        $user->tokens()->where('expires_at', '<', now())->delete();

        $max = (int) config('platform.auth.max_sessions_per_user');
        $excess = $user->tokens()->count() - $max + 1;
        if ($excess > 0) {
            $user->tokens()->orderBy('last_used_at')->orderBy('id')->limit($excess)->get()->each->delete();
        }
    }
}
