<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Domain\AuditLog\Auditor;
use App\Domain\Bookings\Models\Traveler;
use App\Http\Controllers\Controller;
use App\Http\Resources\TravelerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TravelerController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('travelers.manage');
        $q = (string) $request->query('q', '');

        return TravelerResource::collection(Traveler::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('last_name', 'ilike', "%{$q}%")->orWhere('first_name', 'ilike', "%{$q}%")))
            ->orderBy('last_name')
            ->paginate(25));
    }

    /** Full record including the decrypted document number (for pre-filling a booking). */
    public function show(Traveler $traveler): TravelerResource
    {
        $this->authorize('travelers.manage');
        $resource = new TravelerResource($traveler);
        $resource->revealDocument = true;

        return $resource;
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('travelers.manage');
        $traveler = Traveler::query()->create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->auditor->record('traveler.created', $traveler, new: ['name' => $traveler->first_name.' '.$traveler->last_name]);

        return (new TravelerResource($traveler))->response()->setStatusCode(201);
    }

    public function update(Request $request, Traveler $traveler): TravelerResource
    {
        $this->authorize('travelers.manage');
        $traveler->fill($this->validated($request))->save();
        [$old, $new] = Auditor::diff($traveler);
        $this->auditor->record('traveler.updated', $traveler, $old, $new);

        return new TravelerResource($traveler);
    }

    public function destroy(Traveler $traveler): JsonResponse
    {
        $this->authorize('travelers.manage');
        $traveler->delete();
        $this->auditor->record('traveler.deleted', $traveler);

        return response()->json(['message' => 'ok']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $name = 'regex:/^[A-Za-z][A-Za-z \'\-]{0,59}$/';
        $data = $request->validate([
            'title' => ['nullable', Rule::in(['MR', 'MRS', 'MS', 'MISS', 'MSTR'])],
            'first_name' => ['required', 'string', $name],
            'last_name' => ['required', 'string', $name],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'nationality' => ['nullable', 'alpha', 'size:2'],
            'document_type' => ['nullable', Rule::in(['passport', 'national_id'])],
            'document_number' => ['nullable', 'alpha_num', 'min:5', 'max:20'],
            'document_expiry' => ['nullable', 'date_format:Y-m-d'],
            'document_country' => ['nullable', 'alpha', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['first_name', 'last_name', 'nationality', 'document_number', 'document_country'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = strtoupper($data[$key]);
            }
        }
        $data['document_type'] ??= 'passport';

        return $data;
    }
}
