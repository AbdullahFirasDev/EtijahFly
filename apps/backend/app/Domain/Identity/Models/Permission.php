<?php

namespace App\Domain\Identity\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property string $scope
 * @property string|null $group
 */
class Permission extends SpatiePermission
{
    public function label(): string
    {
        return __('permissions.'.$this->name);
    }
}
