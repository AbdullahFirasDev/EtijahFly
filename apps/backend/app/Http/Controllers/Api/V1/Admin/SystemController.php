<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\AuditLog\Models\AuditLog;
use App\Domain\Providers\Models\FlightProvider;
use App\Domain\Providers\Models\ProviderApiLog;
use App\Domain\Shared\Storage\FileStorage;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SystemController extends Controller
{
    public function __construct(private readonly FileStorage $storage) {}

    /** Status of database, cache, queue, storage, scheduler and providers. */
    public function health(): JsonResponse
    {
        $this->authorize('platform.system.view');

        $checks = [
            'database' => $this->check(fn () => DB::select('select 1') && true, fn () => DB::connection()->getDriverName().' '.(DB::selectOne('show server_version')->server_version ?? '')),
            'cache' => $this->check(fn () => Cache::put('health:ping', 1, 10) && Cache::get('health:ping') === 1, fn () => config('cache.default')),
            'redis' => in_array('redis', [config('cache.default'), config('queue.default')], true)
                ? $this->check(fn () => (bool) Redis::connection()->ping(), fn () => config('database.redis.client'))
                : ['status' => 'skipped', 'detail' => 'not used by cache/queue'],
            'queue' => $this->check(fn () => true, fn () => config('queue.default').' · pending '.Queue::size().' · failed '.DB::table('failed_jobs')->count()),
            'storage' => $this->check(fn () => $this->storage->ping(), fn () => config('filestorage.driver')),
            'scheduler' => ($beat = Cache::get('scheduler:heartbeat')) && now()->diffInMinutes($beat, true) < 3
                ? ['status' => 'ok', 'detail' => 'last run '.$beat]
                : ['status' => 'warning', 'detail' => $beat ? 'last run '.$beat : 'no heartbeat yet (is `schedule:work` running?)'],
        ];

        $providers = FlightProvider::query()->orderBy('priority')->get()->map(fn (FlightProvider $p) => [
            'code' => $p->code,
            'enabled' => $p->is_enabled,
            'status' => $p->health_status,
            'checked_at' => $p->health_checked_at?->toIso8601String(),
            'errors_24h' => ProviderApiLog::query()->where('provider_code', $p->code)->where('success', false)->where('created_at', '>=', now()->subDay())->count(),
            'calls_24h' => ProviderApiLog::query()->where('provider_code', $p->code)->where('created_at', '>=', now()->subDay())->count(),
        ]);

        return response()->json([
            'status' => collect($checks)->contains(fn ($c) => $c['status'] === 'error') ? 'degraded' : 'ok',
            'checks' => $checks,
            'providers' => $providers,
            'app' => ['env' => app()->environment(), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'time' => now()->toIso8601String()],
        ]);
    }

    public function providerLogs(Request $request): JsonResponse
    {
        $this->authorize('platform.system.view');

        return response()->json(ProviderApiLog::query()
            ->when($request->query('provider'), fn ($q, $p) => $q->where('provider_code', $p))
            ->when($request->query('errors') === '1', fn ($q) => $q->where('success', false))
            ->latest('id')->paginate(50));
    }

    public function failedJobs(): JsonResponse
    {
        $this->authorize('platform.system.view');

        return response()->json(['data' => DB::table('failed_jobs')->latest('id')->limit(50)->get(['id', 'uuid', 'queue', 'failed_at', DB::raw('substring(exception from 1 for 500) as exception')])]);
    }

    public function auditLogs(Request $request): AnonymousResourceCollection
    {
        $this->authorize('platform.audit.view');

        return AuditLogResource::collection(AuditLog::query()
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($request->query('actor_id'), fn ($q, $id) => $q->where('actor_id', $id))
            ->when($request->query('subject_type'), fn ($q, $t) => $q->where('subject_type', $t)->when($request->query('subject_id'), fn ($q2, $sid) => $q2->where('subject_id', $sid)))
            ->with('actor')
            ->latest('id')->paginate(50));
    }

    /**
     * @param  callable(): bool  $probe
     * @param  callable(): string  $detail
     * @return array{status: string, detail: string}
     */
    private function check(callable $probe, callable $detail): array
    {
        try {
            return ['status' => $probe() ? 'ok' : 'error', 'detail' => (string) $detail()];
        } catch (Throwable $e) {
            return ['status' => 'error', 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
