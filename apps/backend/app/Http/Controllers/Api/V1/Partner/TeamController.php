<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\InvitationService;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions\PermissionCatalog;
use App\Domain\Identity\RoleService;
use App\Domain\Identity\UserManagementService;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Company staff, invitations and custom roles (always within the caller's tenant). */
class TeamController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly RoleService $roles,
        private readonly UserManagementService $users,
    ) {}

    public function users(): AnonymousResourceCollection
    {
        $this->authorize('team.view');

        return UserResource::collection(User::query()->with('roles')->orderBy('name')->get());
    }

    public function invite(Request $request): JsonResponse
    {
        $this->authorize('team.manage');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string'],
        ]);

        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);
        $result = $this->invitations->invite($tenant, $data['email'], $data['name'] ?? null, $data['roles'], $request->user());

        return (new InvitationResource($result['invitation']))->response()->setStatusCode(201);
    }

    public function invitations(): AnonymousResourceCollection
    {
        $this->authorize('team.view');

        return InvitationResource::collection(
            Invitation::query()->where('tenant_id', request()->user()->tenant_id)->with('inviter')->latest('id')->limit(100)->get()
        );
    }

    public function revokeInvitation(Request $request, int $invitation): JsonResponse
    {
        $this->authorize('team.manage');
        $model = Invitation::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($invitation);
        $this->invitations->revoke($model, $request->user());

        return response()->json(['message' => 'ok']);
    }

    public function updateRoles(Request $request, User $user): UserResource
    {
        $this->authorize('team.manage');
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string']]);

        return new UserResource($this->roles->syncRoles($user, $data['roles'], $request->user())->load('roles'));
    }

    public function updateStatus(Request $request, User $user): UserResource
    {
        $this->authorize('team.manage');
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);

        return new UserResource($this->users->setStatus($user, UserStatus::from($data['status']), $request->user())->load('roles'));
    }

    /** Roles assignable in this company (system tenant roles + custom roles). */
    public function roles(Request $request): AnonymousResourceCollection
    {
        $this->authorize('team.view');

        return RoleResource::collection(
            Role::query()->assignableByTenant($request->user()->tenant_id)->with('permissions')->withCount('users')->orderBy('id')->get()
        );
    }

    /** Permission catalogue available for custom company roles. */
    public function permissions(): JsonResponse
    {
        $this->authorize('team.view');

        return response()->json(['data' => collect(PermissionCatalog::TENANT_PERMISSIONS)
            ->map(fn (string $group, string $name) => ['name' => $name, 'group' => $group, 'label' => __('permissions.'.$name)])
            ->values()]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorize('roles.manage');
        $data = $this->validateRole($request);

        $role = $this->roles->createCustomRole($request->user()->tenant_id, $data['name'], $data['description'] ?? null, $data['permissions']);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function updateRole(Request $request, int $role): RoleResource
    {
        $this->authorize('roles.manage');
        $model = $this->tenantRole($request, $role);
        $data = $this->validateRole($request);

        return new RoleResource($this->roles->updateCustomRole($model, $data['name'], $data['description'] ?? null, $data['permissions'])->load('permissions'));
    }

    public function destroyRole(Request $request, int $role): JsonResponse
    {
        $this->authorize('roles.manage');
        $this->roles->deleteCustomRole($this->tenantRole($request, $role));

        return response()->json(['message' => 'ok']);
    }

    private function tenantRole(Request $request, int $id): Role
    {
        return Role::query()->where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    /** @return array{name: string, description?: string|null, permissions: list<string>} */
    private function validateRole(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(array_keys(PermissionCatalog::TENANT_PERMISSIONS))],
        ]);
    }
}
