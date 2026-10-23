<?php

use App\Domain\AuditLog\Auditor;
use App\Domain\Shared\Database\PostgresHardening;
use Illuminate\Support\Facades\DB;

it('enables row level security on every table (Supabase Data API exposure)', function () {
    expect((new PostgresHardening(DB::connection()))->tablesWithoutRls())->toBe([]);
});

it('installs append-only triggers on ledger, audit and booking timeline tables', function () {
    $triggers = collect(DB::select("SELECT event_object_table AS t FROM information_schema.triggers WHERE trigger_name LIKE '%_append_only'"))
        ->pluck('t')->unique()->sort()->values()->all();

    expect($triggers)->toBe(['audit_logs', 'booking_events', 'ledger_entries', 'ledger_transactions']);
});

it('sanitises secrets and document numbers out of audit payloads', function () {
    $clean = Auditor::sanitize([
        'password' => 'p', 'api_key' => 'k', 'token' => 't', 'nested' => ['document_number' => 'A1', 'Authorization' => 'Bearer x', 'name' => 'Ali'],
    ]);

    expect($clean)->toBe([
        'password' => '[redacted]', 'api_key' => '[redacted]', 'token' => '[redacted]',
        'nested' => ['document_number' => '[redacted]', 'Authorization' => '[redacted]', 'name' => 'Ali'],
    ]);
});

it('sends security headers and request ids', function () {
    $this->getJson('/api/v1/health')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Request-Id');
});

it('only allows configured CORS origins', function () {
    config(['cors.allowed_origins' => ['http://localhost:3000']]);

    $evil = $this->withHeaders(['Origin' => 'http://evil.example', 'Access-Control-Request-Method' => 'GET'])->options('/api/v1/health');
    expect($evil->headers->get('Access-Control-Allow-Origin'))->not->toBe('http://evil.example')->not->toBe('*');
    $this->withHeaders(['Origin' => 'http://localhost:3000', 'Access-Control-Request-Method' => 'GET'])->options('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
});

it('localises errors in Arabic by default for the portals', function () {
    $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/partner/dashboard')
        ->assertUnauthorized()->assertJsonPath('message', 'يجب تسجيل الدخول للمتابعة.')
        ->assertHeader('Content-Language', 'ar');
});
