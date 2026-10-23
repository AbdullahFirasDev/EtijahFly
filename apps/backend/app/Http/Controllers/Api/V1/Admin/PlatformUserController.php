<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\InvitationService;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions\PermissionCatalog;
use App\Domain\Identity\RoleService;
use App\Domain\Identity\UserManagementService;
use App\Domain\Shared\Tenancy\TenantScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Platform (internal) users and roles. Agency users are managed per tenant. */
class PlatformUserController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly RoleService $roles,
        private readonly UserManagementService $users,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('platform.users.manage');

        return UserResource::collection(User::query()->whereNull('tenant_id')->where('is_platform', true)->with('roles')->orderBy('name')->get());
    }

    public function invite(Request $request): JsonResponse
    {
        $this->authorize('platform.users.manage');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
        ]);

        $result = $this->invitations->invite(null, $data['email'], $data['name'] ?? null, $data['roles'], $request->user());

        return (new InvitationResource($result['invitation']))->response()->setStatusCode(201);
    }

    public function invitations(): AnonymousResourceCollection
    {
        $this->authorize('platform.users.manage');

        return InvitationResource::collection(Invitation::query()->whereNull('tenant_id')->with('inviter')->latest('id')->limit(100)->get());
    }

    /** Change roles of a platform user, or of an agency user (support cases). */
    public function updateRoles(Request $request, int $user): UserResource
    {
        $this->authorize('platform.users.manage');
        $target = User::query()->withoutGlobalScope(TenantScope::class)->findOrFail($user);
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string']]);

        return new UserResource($this->roles->syncRoles($target, $data['roles'], $request->user())->load('roles'));
    }

    public function updateStatus(Request $request, int $user): UserResource
    {
        $target = User::query()->withoutGlobalScope(TenantScope::class)->findOrFail($user);
        $this->authorize($target->tenant_id ? 'platform.tenants.manage' : 'platform.users.manage');
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        return new UserResource($this->users->setStatus($target, UserStatus::from($data['status']), $request->user())->load('roles'));
    }

    public function roles(): AnonymousResourceCollection
    {
        $this->authorize('platform.users.manage');

        return RoleResource::collection(Role::query()->whereNull('tenant_id')->with('permissions')->withCount('users')->orderBy('scope')->orderBy('id')->get());
    }

    public function permissions(): JsonResponse
    {
        $this->authorize('platform.users.manage');

        return response()->json(['data' => collect(PermissionCatalog::PLATFORM_PERMISSIONS)
            ->map(fn (string $group, string $name) => ['name' => $name, 'group' => $group, 'label' => __('permissions.'.$name)])
            ->values()]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorize('platform.users.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionCatalog::PLATFORM_PERMISSIONS))],
        ]);

        $role = $this->roles->createCustomRole(null, $data['name'], $data['description'] ?? null, $data['permissions']);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function destroyRole(int $role): JsonResponse
    {
        $this->authorize('platform.users.manage');
        $this->roles->deleteCustomRole(Role::query()->whereNull('tenant_id')->findOrFail($role));

        return response()->json(['message' => 'ok']);
    }
}
