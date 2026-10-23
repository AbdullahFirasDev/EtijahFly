<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'status' => $this->status->value,
            'is_platform' => $this->is_platform,
            'tenant_id' => $this->tenant_id,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Role $r) => ['name' => $r->name, 'label' => $r->label()])->values()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
