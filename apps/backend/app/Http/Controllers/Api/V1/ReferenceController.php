<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Airlines\Models\Airline;
use App\Domain\Airlines\Models\Airport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reference data for forms (local directory only - never triggers external API calls). */
class ReferenceController extends Controller
{
    /** Airport autocomplete by IATA code or city/name (Arabic or English). */
    public function airports(Request $request): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? ''));

        $airports = Airport::query()
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn ($w) => $w->where('iata_code', strtoupper($q))
                    ->orWhere('city_en', 'ilike', $like)->orWhere('city_ar', 'ilike', $like)
                    ->orWhere('name_en', 'ilike', $like)->orWhere('name_ar', 'ilike', $like));
            })
            ->orderByRaw('CASE WHEN iata_code = ? THEN 0 ELSE 1 END', [strtoupper($q)])
            ->orderBy('city_en')
            ->limit(20)
            ->get(['iata_code', 'name_en', 'name_ar', 'city_en', 'city_ar', 'country_code']);

        return response()->json(['data' => $airports]);
    }

    public function airlines(Request $request): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? ''));

        return response()->json(['data' => Airline::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('iata_code', strtoupper($q))->orWhere('icao_code', strtoupper($q))->orWhere('name', 'ilike', '%'.$q.'%')))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'iata_code', 'icao_code', 'name', 'country'])]);
    }
}
