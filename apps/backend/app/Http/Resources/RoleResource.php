<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label(),
            'description' => $this->description,
            'scope' => $this->scope,
            'is_system' => $this->is_system,
            'is_custom' => ! $this->is_system,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn ($permission): string => $permission->name)->values()->all()),
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
