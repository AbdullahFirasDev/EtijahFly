<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\InvitationService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    /**
     * Inspect an invitation before accepting it.
     *
     * @unauthenticated
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->findPending($token);
        $tenant = $invitation->tenant_id ? Tenant::query()->find($invitation->tenant_id) : null;

        return response()->json([
            'email' => $invitation->email,
            'name' => $invitation->name,
            'organisation' => $tenant ? $tenant->name : config('platform.name'),
            'portal' => $tenant ? 'partner' : 'admin',
            'roles' => array_map(fn (string $r) => ['name' => $r, 'label' => __('roles.'.$r)], $invitation->roles),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Accept an invitation: creates the account with the invited roles.
     *
     * @unauthenticated
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->invitations->accept($token, $data['name'], $data['password'], $data['phone'] ?? null);

        return response()->json(['message' => 'ok', 'email' => $user->email, 'portal' => $user->portal()], 201);
    }
}
