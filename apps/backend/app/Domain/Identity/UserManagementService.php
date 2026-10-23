<?php

namespace App\Domain\Identity;

use App\Domain\AuditLog\Auditor;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\BusinessRuleException;

class UserManagementService
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly Auditor $auditor,
    ) {}

    public function setStatus(User $target, UserStatus $status, User $actor): User
    {
        if ($target->is($actor)) {
            throw BusinessRuleException::make('CANNOT_CHANGE_SELF', __('errors.cannot_disable_self'), 403);
        }
        if ($status === UserStatus::Disabled && $target->tenant_id && $target->hasRole('company_owner')) {
            $this->roles->assertNotLastOwner($target);
        }

        $before = $target->status;
        $target->forceFill(['status' => $status])->save();

        if ($status === UserStatus::Disabled) {
            $target->tokens()->delete(); // end all sessions immediately
        }

        $this->auditor->record($status === UserStatus::Disabled ? 'user.disabled' : 'user.enabled', $target, ['status' => $before->value], ['status' => $status->value]);

        return $target;
    }
}
