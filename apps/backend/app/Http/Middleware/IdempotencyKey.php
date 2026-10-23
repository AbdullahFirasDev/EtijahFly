<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an `Idempotency-Key` header on money-moving endpoints.
 *  - same key + same payload, completed  -> stored response is replayed (no second booking/charge);
 *  - same key + different payload        -> 422 IDEMPOTENCY_KEY_REUSED;
 *  - same key while first is running     -> 409 REQUEST_IN_PROGRESS;
 *  - non-2xx outcomes are not stored, so the client may retry with the same key.
 * The services add database-level guarantees (unique keys on bookings/payments/ledger).
 */
class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9\-_:]{8,120}$/', $key)) {
            return response()->json([
                'message' => __('errors.idempotency_key_required'),
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
            ], 400);
        }

        $userId = $request->user()->id;
        $scope = $request->method().' '.($request->route()?->getName() ?? $request->path());
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($this->canonical($request->except(['_token']))));

        $existing = DB::table('idempotency_keys')->where(['user_id' => $userId, 'scope' => $scope, 'key' => $key])->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            $id = DB::table('idempotency_keys')->insertGetId([
                'user_id' => $userId, 'scope' => $scope, 'key' => $key, 'request_hash' => $hash, 'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay(DB::table('idempotency_keys')->where(['user_id' => $userId, 'scope' => $scope, 'key' => $key])->first(), $hash);
        }

        $request->attributes->set('idempotency_key', $key);

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('id', $id)->delete();
            throw $e;
        }

        if ($response->isSuccessful() && $response instanceof JsonResponse) {
            DB::table('idempotency_keys')->where('id', $id)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'completed_at' => now(),
            ]);
        } else {
            DB::table('idempotency_keys')->where('id', $id)->delete();
        }

        return $response;
    }

    private function replay(?object $record, string $hash): Response
    {
        if (! $record) {
            return response()->json(['message' => __('errors.request_in_progress'), 'code' => 'REQUEST_IN_PROGRESS'], 409);
        }
        if (! hash_equals($record->request_hash, $hash)) {
            return response()->json(['message' => __('errors.idempotency_key_reused'), 'code' => 'IDEMPOTENCY_KEY_REUSED'], 422);
        }
        if (! $record->completed_at) {
            return response()->json(['message' => __('errors.request_in_progress'), 'code' => 'REQUEST_IN_PROGRESS'], 409);
        }

        return response($record->response_body, $record->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    /** @param array<array-key, mixed> $data */
    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->canonical($v);
            } elseif (is_object($v)) {
                $data[$k] = method_exists($v, 'getClientOriginalName') ? 'file:'.$v->getClientOriginalName().':'.$v->getSize() : get_class($v);
            }
        }

        return $data;
    }
}
