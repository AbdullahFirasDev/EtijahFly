<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\AuditLog\Auditor;
use App\Domain\Identity\AuthService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\BrandingService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandingResource;
use App\Http\Resources\TenantResource;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly BrandingService $branding,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Log in to a portal and receive an API token.
     *
     * The token is meant to be stored server-side by the portal (httpOnly cookie), not in
     * browser storage. Wrong portal, unknown e-mail and wrong password share one error.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'portal' => ['required', Rule::in(AuthService::PORTALS)],
        ]);

        $token = $this->auth->login($data['email'], $data['password'], $data['portal'], $request->ip(), $request->userAgent());
        /** @var User $user */
        $user = $token->accessToken->tokenable;

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->mePayload($user),
        ]);
    }

    /** Current user, roles, effective permissions and (for agencies) company + branding. */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->mePayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $this->auth->logout($request->user(), $token);

        return response()->json(['message' => 'ok']);
    }

    /** Active sessions (tokens) of the current user. */
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        return response()->json([
            'data' => $request->user()->tokens()->orderByDesc('last_used_at')->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'device' => $t->name,
                'ip_address' => $t->ip_address,
                'user_agent' => $t->user_agent,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'current' => $t->id === $currentId,
            ]),
        ]);
    }

    public function revokeSession(Request $request, int $session): JsonResponse
    {
        $this->auth->revokeSession($request->user(), $session);

        return response()->json(['message' => 'ok']);
    }

    /** Change password; all other sessions are signed out. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);

        $this->auth->changePassword($request->user(), $data['current_password'], $data['password'], $request->user()->currentAccessToken()->id);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Request a password reset link. Always returns the same response.
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        Password::sendResetLink(['email' => mb_strtolower($data['email'])]);
        $this->auditor->record('auth.password_reset_requested', metadata: ['email' => mb_strtolower($data['email'])], actorType: 'system');

        return response()->json(['message' => __('passwords.sent')]);
    }

    /** @unauthenticated */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => mb_strtolower($data['email'])] + $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
                $this->auditor->record('auth.password_reset', $user, actor: $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status), 'code' => 'RESET_FAILED'], 422);
        }

        return response()->json(['message' => __($status)]);
    }

    /** @return array<string, mixed> */
    private function mePayload(User $user): array
    {
        $user->load('roles');
        $tenant = $user->tenant_id ? Tenant::query()->find($user->tenant_id) : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'portal' => $user->portal(),
            'roles' => $user->roles->map(fn ($r) => ['name' => $r->name, 'label' => $r->label()])->values(),
            'permissions' => $user->scopedPermissionNames(),
            'tenant' => $tenant ? (new TenantResource($tenant))->resolve() : null,
            'branding' => $tenant ? (new BrandingResource($this->branding->for($tenant)))->resolve() : null,
        ];
    }
}
