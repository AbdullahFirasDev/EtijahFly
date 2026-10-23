<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__test/ip', fn (Request $request) => ['ip' => $request->ip(), 'secret_header' => $request->headers->get('X-Portal-Secret')]);
});

it('uses the portal-supplied client IP only with the shared secret', function () {
    config(['platform.portal_proxy_secret' => 'portal-secret-for-tests']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson('/__test/ip', ['X-Portal-Secret' => 'portal-secret-for-tests', 'X-Portal-Client-Ip' => '198.51.100.7'])
        ->assertOk()
        ->assertJson(['ip' => '198.51.100.7', 'secret_header' => null]);
});

it('ignores the client IP header without a matching secret', function (?string $configured, string $sent) {
    config(['platform.portal_proxy_secret' => $configured]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson('/__test/ip', ['X-Portal-Secret' => $sent, 'X-Portal-Client-Ip' => '198.51.100.7'])
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.10', 'secret_header' => null]);
})->with([
    'secret not configured' => [null, 'anything'],
    'wrong secret' => ['portal-secret-for-tests', 'guess'],
]);

it('rejects a malformed client IP even with the secret', function () {
    config(['platform.portal_proxy_secret' => 'portal-secret-for-tests']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson('/__test/ip', ['X-Portal-Secret' => 'portal-secret-for-tests', 'X-Portal-Client-Ip' => 'not-an-ip'])
        ->assertJson(['ip' => '203.0.113.10']);
});
