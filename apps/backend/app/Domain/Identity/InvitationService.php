<?php

namespace App\Domain\Identity;

use App\Domain\AuditLog\Auditor;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationNotification;
use App\Domain\Shared\Exceptions\BusinessRuleException;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitationService
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>  $roleNames
     * @return array{invitation: Invitation, token: string}
     */
    public function invite(?Tenant $tenant, string $email, ?string $name, array $roleNames, ?User $inviter): array
    {
        $email = mb_strtolower(trim($email));
        $this->roles->resolveAssignable($tenant?->id, $roleNames);

        if (User::query()->withoutGlobalScopes()->where('email', $email)->exists()) {
            throw BusinessRuleException::make('EMAIL_TAKEN', __('errors.email_taken'), 422);
        }

        // Re-inviting replaces any pending invitation for the same address.
        Invitation::query()->where('email', $email)->pending()->update(['revoked_at' => now()]);

        $token = Str::random(64);
        $invitation = Invitation::query()->create([
            'tenant_id' => $tenant?->id,
            'email' => $email,
            'name' => $name,
            'roles' => array_values(array_unique($roleNames)),
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addHours((int) config('platform.auth.invitation_ttl_hours')),
        ]);

        $portal = $tenant ? 'partner' : 'admin';
        $url = rtrim((string) config("platform.portals.{$portal}"), '/').'/invite/'.$token;
        Notification::route('mail', $email)->notify(new InvitationNotification($tenant ? $tenant->name : config('platform.name'), $url, $invitation->expires_at));

        $this->auditor->record('user.invited', $invitation, new: ['email' => $email, 'roles' => $roleNames], tenantId: $tenant?->id, actor: $inviter);

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function findPending(string $token): Invitation
    {
        $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->first();

        if (! $invitation || ! $invitation->isPending()) {
            throw BusinessRuleException::make('INVITATION_INVALID', __('errors.invitation_invalid'), 410);
        }

        return $invitation;
    }

    public function accept(string $token, string $name, string $password, ?string $phone): User
    {
        return DB::transaction(function () use ($token, $name, $password, $phone) {
            $invitation = $this->findPending($token);
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $invitation->isPending()) {
                throw BusinessRuleException::make('INVITATION_INVALID', __('errors.invitation_invalid'), 410);
            }

            if ($invitation->tenant_id && ! Tenant::query()->find($invitation->tenant_id)?->isActive()) {
                throw BusinessRuleException::make('TENANT_SUSPENDED', __('errors.tenant_suspended'), 403);
            }

            $user = User::query()->withoutGlobalScopes()->create([
                'tenant_id' => $invitation->tenant_id,
                'is_platform' => $invitation->tenant_id === null,
                'name' => $name,
                'email' => $invitation->email,
                'phone' => $phone,
                'password' => $password,
                'password_changed_at' => now(),
                'email_verified_at' => now(), // possession of the e-mailed token proves the address
                'status' => UserStatus::Active,
            ]);
            $user->syncRoles($this->roles->resolveAssignable($invitation->tenant_id, $invitation->roles));

            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->auditor->record('user.invitation_accepted', $user, new: ['roles' => $invitation->roles], tenantId: $invitation->tenant_id, actor: $user);

            return $user;
        });
    }

    public function revoke(Invitation $invitation, User $actor): void
    {
        if (! $invitation->isPending()) {
            throw BusinessRuleException::make('INVITATION_INVALID', __('errors.invitation_invalid'), 409);
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->auditor->record('user.invitation_revoked', $invitation, new: ['email' => $invitation->email], tenantId: $invitation->tenant_id, actor: $actor);
    }
}
