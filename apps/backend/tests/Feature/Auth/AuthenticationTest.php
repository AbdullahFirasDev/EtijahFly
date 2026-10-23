<?php

use App\Domain\AuditLog\Models\AuditLog;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

function login(string $email, string $password = 'Password123!', string $portal = 'partner')
{
    return test()->postJson('/api/v1/auth/login', compact('email', 'password', 'portal'));
}

it('logs an agency user into the partner portal and returns permissions and branding', function () {
    $user = agencyUser(agency(), 'booking_agent');

    $response = login($user->email)->assertOk()
        ->assertJsonPath('user.portal', 'partner')
        ->assertJsonPath('user.tenant.id', $user->tenant_id)
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['permissions', 'branding' => ['display_name', 'primary_color']]]);

    expect($response->json('user.permissions'))->toContain('bookings.create')->not->toContain('finance.view');

    $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('email', $user->email);
});

it('uses one generic error for wrong password, unknown e-mail and wrong portal', function () {
    $agent = agencyUser(agency());
    $admin = platformUser();

    login($agent->email, 'wrong-password')->assertStatus(422)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    login('nobody@example.com')->assertStatus(422)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    login($admin->email, portal: 'partner')->assertStatus(422)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    login($agent->email, portal: 'admin')->assertStatus(422)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect(AuditLog::query()->where('action', 'auth.login_failed')->count())->toBe(4);
});

it('records failed logins without storing the password', function () {
    $agent = agencyUser(agency());
    login($agent->email, 'Sup3r-Secret-Guess!');

    $log = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail();
    expect(json_encode($log->getAttributes()))->not->toContain('Sup3r-Secret-Guess!');
});

it('blocks disabled users and suspended agencies', function () {
    $tenant = agency();
    $disabled = User::factory()->forTenant($tenant)->disabled()->withRole('booking_agent')->create();
    login($disabled->email)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');

    $suspendedTenant = agency();
    $user = agencyUser($suspendedTenant);
    $suspendedTenant->forceFill(['status' => 'suspended'])->save();
    login($user->email)->assertStatus(403)->assertJsonPath('code', 'TENANT_SUSPENDED');
});

it('rate limits login attempts per e-mail', function () {
    $user = agencyUser(agency());
    foreach (range(1, 5) as $i) {
        login($user->email, 'wrong');
    }

    login($user->email, 'wrong')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

it('revokes access immediately when a user is disabled or the agency suspended', function () {
    $tenant = agency();
    $user = agencyUser($tenant, 'booking_agent');
    $token = login($user->email)->json('token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $tenant->forceFill(['status' => 'suspended'])->save();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'TENANT_SUSPENDED');
});

it('expires tokens absolutely and after inactivity', function () {
    $user = agencyUser(agency());
    $token = login($user->email)->json('token');
    $model = PersonalAccessToken::findToken($token);

    $this->travel((int) config('platform.auth.token_idle_minutes') + 1)->minutes();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->travelBack();

    $model->forceFill(['last_used_at' => now(), 'expires_at' => now()->subMinute()])->save();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('lists and revokes sessions; logout deletes the current token', function () {
    $user = agencyUser(agency());
    $first = login($user->email)->json('token');
    $second = login($user->email)->json('token');

    $sessions = $this->withToken($second)->getJson('/api/v1/auth/sessions')->assertOk()->json('data');
    expect($sessions)->toHaveCount(2);
    $other = collect($sessions)->firstWhere('current', false);

    $this->withToken($second)->deleteJson("/api/v1/auth/sessions/{$other['id']}")->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($first)->getJson('/api/v1/auth/me')->assertStatus(401);

    $this->app['auth']->forgetGuards();
    $this->withToken($second)->postJson('/api/v1/auth/logout')->assertOk();
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('changes password, enforces the policy and signs out other sessions', function () {
    $user = agencyUser(agency());
    $other = login($user->email)->json('token');
    $current = login($user->email)->json('token');

    $this->withToken($current)->putJson('/api/v1/auth/password', [
        'current_password' => 'Password123!', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->withToken($current)->putJson('/api/v1/auth/password', [
        'current_password' => 'Password123!', 'password' => 'N3w-Strong-Pass!', 'password_confirmation' => 'N3w-Strong-Pass!',
    ])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertStatus(401);
    login($user->email, 'N3w-Strong-Pass!')->assertOk();
});

it('sends reset links without revealing whether the e-mail exists', function () {
    Notification::fake();
    $user = agencyUser(agency());

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json('message');
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk()->json('message');

    expect($known)->toBe($unknown);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
        $url = ($n->toMail($user))->actionUrl;

        return str_starts_with($url, config('platform.portals.partner').'/reset-password?token=');
    });
});

it('resets the password with a valid token and revokes every session', function () {
    $user = agencyUser(agency());
    $token = login($user->email)->json('token');
    $resetToken = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken, 'email' => $user->email, 'password' => 'Brand-New-Pass1!', 'password_confirmation' => 'Brand-New-Pass1!',
    ])->assertOk();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    login($user->email, 'Brand-New-Pass1!')->assertOk();
});
