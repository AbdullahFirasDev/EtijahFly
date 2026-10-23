<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invitation */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'roles' => $this->roles,
            'status' => $this->status(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
