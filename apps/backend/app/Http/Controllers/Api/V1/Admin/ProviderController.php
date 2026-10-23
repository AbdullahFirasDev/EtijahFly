<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Airlines\AirlineDirectory;
use App\Domain\Airlines\AirlinesInfoProvider;
use App\Domain\Airlines\Models\Airline;
use App\Domain\AuditLog\Auditor;
use App\Domain\Providers\Exceptions\ProviderException;
use App\Domain\Providers\Models\FlightProvider;
use App\Domain\Providers\ProviderManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Integration management. Credentials are never shown or editable here - they are read from
 * the server environment; the API only reports whether they are configured.
 */
class ProviderController extends Controller
{
    private const MOCK_SCENARIOS = [
        'normal', 'no_results', 'search_error', 'auth_error', 'price_change', 'unavailable', 'hold_rejected',
        'hold_timeout', 'ticketing_failed', 'ticketing_timeout', 'ticketing_pending', 'refund_pending', 'cancel_failed',
    ];

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly AirlinesInfoProvider $airlinesApi,
        private readonly AirlineDirectory $directory,
        private readonly Auditor $auditor,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('platform.providers.view');

        return response()->json(['data' => FlightProvider::query()->orderBy('priority')->get()->map(fn (FlightProvider $p) => $this->present($p))]);
    }

    /** Enable/disable a provider, change priority or non-secret settings. */
    public function update(Request $request, FlightProvider $provider): JsonResponse
    {
        $this->authorize('platform.providers.manage');
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'settings' => ['sometimes', 'array'],
            'settings.scenario' => ['sometimes', Rule::in(self::MOCK_SCENARIOS)],
        ]);

        if (($data['is_enabled'] ?? false) && $provider->kind === FlightProvider::KIND_BOOKING && ! config("providers.booking.{$provider->code}.enabled")) {
            return response()->json(['message' => __('errors.provider_error'), 'code' => 'PROVIDER_DISABLED_BY_ENVIRONMENT'], 422);
        }

        $provider->fill($data)->save();
        [$old, $new] = Auditor::diff($provider);
        $this->auditor->record('provider.updated', $provider, $old, $new);
        $this->providers->flush();

        return response()->json(['data' => $this->present($provider)]);
    }

    /** Run a live health check against the provider and store the result. */
    public function healthCheck(FlightProvider $provider): JsonResponse
    {
        $this->authorize('platform.providers.manage');

        try {
            if ($provider->kind === FlightProvider::KIND_AIRLINE_DATA) {
                // A lookup (cached for 30 days) proves key + connectivity without burning quota on every check.
                $ok = $this->airlinesApi->isConfigured() && count($this->airlinesApi->findByIata('IA')) > 0;
            } else {
                $ok = $this->providers->get($provider->code)->healthCheck();
            }
            $message = $ok ? 'ok' : 'unhealthy';
        } catch (ProviderException $e) {
            $ok = false;
            $message = $e->errorCode.': '.$e->getMessage();
        }

        $provider->forceFill(['health_status' => $ok ? 'healthy' : 'unhealthy', 'health_message' => mb_substr($message, 0, 255), 'health_checked_at' => now()])->save();

        return response()->json(['data' => $this->present($provider)]);
    }

    /** Airline directory with sync metadata. */
    public function airlines(Request $request): JsonResponse
    {
        $this->authorize('platform.providers.view');
        $q = (string) $request->query('q', '');

        return response()->json([
            'data' => Airline::query()
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('iata_code', strtoupper($q))->orWhere('icao_code', strtoupper($q))->orWhere('name', 'ilike', "%{$q}%")))
                ->orderBy('name')->paginate(50),
            'quota' => $this->airlinesApi->quota(),
        ]);
    }

    /** Import/refresh airlines from the Airlines API (consumes quota; results are cached). */
    public function importAirlines(Request $request): JsonResponse
    {
        $this->authorize('platform.providers.manage');
        $data = $request->validate([
            'by' => ['required', Rule::in(['iata', 'icao', 'name'])],
            'value' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $airlines = $this->directory->import($data['by'], $data['value']);
        $this->auditor->record('airlines.imported', metadata: ['by' => $data['by'], 'value' => $data['value'], 'count' => count($airlines)]);

        return response()->json(['data' => $airlines, 'quota' => $this->airlinesApi->quota()]);
    }

    /** @return array<string, mixed> */
    private function present(FlightProvider $p): array
    {
        $isBooking = $p->kind === FlightProvider::KIND_BOOKING;
        $capabilities = null;
        if ($isBooking && $this->providers->isConfigured($p->code)) {
            $capabilities = $this->providers->get($p->code)->capabilities()->toArray();
        }

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'kind' => $p->kind,
            'is_enabled' => $p->is_enabled,
            'is_sandbox' => $p->is_sandbox,
            'priority' => $p->priority,
            'settings' => $p->settings,
            'environment_allows' => $isBooking ? (bool) config("providers.booking.{$p->code}.enabled") : true,
            'credentials_configured' => $isBooking ? $this->providers->hasCredentials($p->code) : $this->airlinesApi->isConfigured(),
            'capabilities' => $capabilities ?? ($isBooking ? null : ['airline_info' => true]),
            'scenarios' => $p->code === 'mock' ? self::MOCK_SCENARIOS : null,
            'health' => ['status' => $p->health_status, 'message' => $p->health_message, 'checked_at' => $p->health_checked_at?->toIso8601String()],
            'quota' => $p->kind === FlightProvider::KIND_AIRLINE_DATA ? $this->airlinesApi->quota() : null,
        ];
    }
}
