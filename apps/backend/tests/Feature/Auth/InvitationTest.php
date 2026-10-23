<?php

use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

function captureInviteToken(): string
{
    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $n) use (&$token) {
        $token = basename(parse_url($n->url, PHP_URL_PATH));

        return true;
    });

    return $token;
}

it('lets a company owner invite staff who then activate their account', function () {
    Notification::fake();
    $tenant = agency();
    actingAsPartner(agencyUser($tenant, 'company_owner'));

    $this->postJson('/api/v1/partner/team/invitations', ['email' => 'New.Agent@Example.com', 'name' => 'New Agent', 'roles' => ['booking_agent']])
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    $token = captureInviteToken();
    expect(Invitation::query()->firstOrFail()->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);

    $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('email', 'new.agent@example.com')->assertJsonPath('portal', 'partner');

    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'New Agent', 'password' => 'Welcome-2026!x', 'password_confirmation' => 'Welcome-2026!x',
    ])->assertCreated();

    $user = User::query()->withoutGlobalScopes()->where('email', 'new.agent@example.com')->firstOrFail();
    expect($user->tenant_id)->toBe($tenant->id)->and($user->hasRole('booking_agent'))->toBeTrue();

    // Single use
    $this->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'X', 'password' => 'Welcome-2026!x', 'password_confirmation' => 'Welcome-2026!x',
    ])->assertStatus(410)->assertJsonPath('code', 'INVITATION_INVALID');
});

it('rejects expired invitations', function () {
    Notification::fake();
    actingAsPartner(agencyUser(agency()));
    $this->postJson('/api/v1/partner/team/invitations', ['email' => 'late@example.com', 'roles' => ['accountant']])->assertCreated();
    $token = captureInviteToken();

    $this->travel(73)->hours();
    $this->getJson("/api/v1/invitations/{$token}")->assertStatus(410);
});

it('never lets an agency grant platform roles', function () {
    Notification::fake();
    actingAsPartner(agencyUser(agency()));

    $this->postJson('/api/v1/partner/team/invitations', ['email' => 'evil@example.com', 'roles' => ['super_admin']])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_ROLES');
});

it('requires team.manage to invite', function () {
    actingAsPartner(agencyUser(agency(), 'booking_agent'));

    $this->postJson('/api/v1/partner/team/invitations', ['email' => 'x@example.com', 'roles' => ['booking_agent']])->assertForbidden();
});
