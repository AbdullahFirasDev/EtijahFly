<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money\Money;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantService;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\LedgerAccountResource;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.tenants.view');
        $q = (string) $request->query('q', '');

        return TenantResource::collection(Tenant::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('slug', 'ilike', "%{$q}%")->orWhere('contact_email', 'ilike', "%{$q}%")))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->with('ledgerAccounts')
            ->withCount('users')
            ->latest('id')
            ->paginate(25));
    }

    /** Onboard an agency; an invitation is e-mailed to the owner. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('platform.tenants.manage');
        $data = $request->validate($this->rules() + [
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'credit_limit' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);

        if (isset($data['credit_limit']) && (float) $data['credit_limit'] > 0) {
            $this->authorize('platform.credit.manage');
        }

        $result = $this->tenants->create($data, $request->user());

        return response()->json([
            'tenant' => new TenantResource($result['tenant']->load(['ledgerAccounts', 'branding'])),
            'invitation_id' => $result['invitation_id'],
        ], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $this->authorize('platform.tenants.view');

        return response()->json([
            'tenant' => new TenantResource($tenant->load(['ledgerAccounts', 'branding'])->loadCount('users')),
            'users' => UserResource::collection(User::query()->where('tenant_id', $tenant->id)->with('roles')->orderBy('name')->get()),
            'invitations' => InvitationResource::collection(Invitation::query()->where('tenant_id', $tenant->id)->latest('id')->limit(20)->get()),
        ]);
    }

    public function update(Request $request, Tenant $tenant): TenantResource
    {
        $this->authorize('platform.tenants.manage');
        $data = $request->validate($this->rules($tenant));

        return new TenantResource($this->tenants->update($tenant, $data)->load('ledgerAccounts'));
    }

    public function suspend(Request $request, Tenant $tenant): TenantResource
    {
        $this->authorize('platform.tenants.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new TenantResource($this->tenants->suspend($tenant, $data['reason']));
    }

    public function activate(Tenant $tenant): TenantResource
    {
        $this->authorize('platform.tenants.manage');

        return new TenantResource($this->tenants->activate($tenant));
    }

    /** Set the credit limit (and low-balance alert threshold) of an agency wallet. */
    public function creditLimit(Request $request, Tenant $tenant): LedgerAccountResource
    {
        $this->authorize('platform.credit.manage');
        $data = $request->validate([
            'currency' => ['required', Rule::in(config('platform.currencies'))],
            'credit_limit' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'low_balance_threshold' => ['nullable', 'decimal:0,2', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return new LedgerAccountResource($this->tenants->setCreditLimit(
            $tenant, $data['currency'], Money::of((string) $data['credit_limit'], $data['currency']), $data['reason'], $data['low_balance_threshold'] ?? null, $request->user(),
        ));
    }

    /** Freeze/unfreeze an agency wallet (blocks new charges). */
    public function accountStatus(Request $request, Tenant $tenant): LedgerAccountResource
    {
        $this->authorize('platform.credit.manage');
        $data = $request->validate([
            'currency' => ['required', Rule::in(config('platform.currencies'))],
            'status' => ['required', Rule::in(['active', 'frozen'])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return new LedgerAccountResource($this->tenants->setAccountStatus($tenant, $data['currency'], $data['status'], $data['reason'], $request->user()));
    }

    /** @return array<string, mixed> */
    private function rules(?Tenant $tenant = null): array
    {
        $required = $tenant ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'slug' => ['nullable', 'alpha_dash', 'max:63', Rule::unique('tenants', 'slug')->ignore($tenant?->id)],
            'country_code' => ['nullable', 'alpha', 'size:2'],
            'default_currency' => [$tenant ? 'prohibited' : 'required', Rule::in(config('platform.currencies'))],
            'contact_email' => [$required, 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'custom_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/', Rule::unique('tenants', 'custom_domain')->ignore($tenant?->id)],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ];
    }
}
