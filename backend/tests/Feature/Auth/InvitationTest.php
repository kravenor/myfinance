<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        // Il punto degli inviti: funzionano con la registrazione chiusa.
        config(['finance.registration' => false]);
        // phpunit.xml usa il mailer array, che il controller considera «non recapita».
        config(['mail.default' => 'smtp']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /**
     * @return array<string, string>
     */
    private function registration(string $token, string $email = 'invitato@example.com'): array
    {
        return [
            'name' => 'Invitato',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invitation_token' => $token,
        ];
    }

    private function tokenFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['invite'];
    }

    public function test_non_admin_cannot_manage_invitations(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/invitations')->assertForbidden();
        $this->actingAs($user)->postJson('/api/invitations', [])->assertForbidden();
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_is_admin_cannot_be_set_through_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/auth/preferences', ['is_admin' => true]);

        $this->assertFalse((bool) $user->fresh()?->is_admin);
    }

    public function test_admin_creates_email_bound_invitation_and_email_is_sent(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->admin())->postJson('/api/invitations', ['email' => 'Invitato@Example.com'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'invitato@example.com')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('email_sent', true);

        $token = $this->tokenFrom($response->json('url'));
        $this->assertDatabaseHas('invitations', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
        $response->assertJsonMissingPath('data.token_hash');

        Notification::assertSentTo(
            new AnonymousNotifiable,
            InvitationNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'invitato@example.com',
        );
    }

    public function test_admin_can_skip_the_email(): void
    {
        Notification::fake();

        $this->actingAs($this->admin())->postJson('/api/invitations', ['email' => 'a@example.com', 'send_email' => false])
            ->assertCreated()->assertJsonPath('email_sent', false);

        Notification::assertNothingSent();
    }

    public function test_log_mailer_does_not_claim_the_email_was_sent(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $this->actingAs($this->admin())->postJson('/api/invitations', ['email' => 'a@example.com'])
            ->assertCreated()->assertJsonPath('email_sent', false);

        Notification::assertNothingSent();
    }

    public function test_generic_invitation_sends_no_email(): void
    {
        Notification::fake();

        $this->actingAs($this->admin())->postJson('/api/invitations', [])
            ->assertCreated()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('email_sent', false);

        Notification::assertNothingSent();
    }

    public function test_cannot_invite_an_existing_account(): void
    {
        User::factory()->create(['email' => 'esiste@example.com']);

        $this->actingAs($this->admin())->postJson('/api/invitations', ['email' => 'esiste@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Esiste già un account con questa email.');
    }

    public function test_invited_user_registers_with_registration_closed(): void
    {
        [$invitation, $token] = Invitation::issue($this->admin(), 'invitato@example.com');

        $this->getJson("/api/auth/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('email', 'invitato@example.com');

        $this->postJson('/api/auth/register', $this->registration($token))->assertCreated();

        $user = User::where('email', 'invitato@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->id, $invitation->accepted_user_id);
    }

    public function test_bound_invitation_rejects_a_different_email(): void
    {
        [, $token] = Invitation::issue($this->admin(), 'invitato@example.com');

        $this->postJson('/api/auth/register', $this->registration($token, 'altro@example.com'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'altro@example.com']);
    }

    public function test_generic_invitation_accepts_any_email(): void
    {
        [, $token] = Invitation::issue($this->admin(), null);

        $this->getJson("/api/auth/invitations/{$token}")->assertOk()->assertJsonPath('email', null);
        $this->postJson('/api/auth/register', $this->registration($token, 'chiunque@example.com'))->assertCreated();
    }

    public function test_invitation_is_single_use(): void
    {
        [, $token] = Invitation::issue($this->admin(), null);

        $this->postJson('/api/auth/register', $this->registration($token, 'primo@example.com'))->assertCreated();
        auth()->guard('web')->logout();

        $this->postJson('/api/auth/register', $this->registration($token, 'secondo@example.com'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Invito non valido o scaduto.');
        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
    }

    public function test_expired_and_unknown_tokens_are_rejected(): void
    {
        [$invitation, $token] = Invitation::issue($this->admin(), null);
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
        $this->getJson('/api/auth/invitations/inesistente')->assertNotFound();
        $this->postJson('/api/auth/register', $this->registration($token))->assertForbidden();
        $this->postJson('/api/auth/register', $this->registration('inesistente'))->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_revoked_invitation_no_longer_works(): void
    {
        $admin = $this->admin();
        [$invitation, $token] = Invitation::issue($admin, null);

        $this->actingAs($admin)->deleteJson("/api/invitations/{$invitation->id}")->assertNoContent();
        auth()->guard('web')->logout();

        $this->postJson('/api/auth/register', $this->registration($token))->assertForbidden();
    }

    public function test_admin_lists_invitations_with_status(): void
    {
        $admin = $this->admin();
        Invitation::issue($admin, 'attesa@example.com');
        [$expired] = Invitation::issue($admin, null);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($admin)->getJson('/api/invitations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.1.status', 'pending')
            ->assertJsonPath('data.1.invited_by', $admin->name);
    }

    public function test_console_command_grants_and_revokes_admin(): void
    {
        $user = User::factory()->create(['email' => 'capo@example.com']);

        $this->artisan('user:admin', ['email' => 'capo@example.com'])->assertSuccessful();
        $this->assertTrue((bool) $user->fresh()?->is_admin);

        $this->artisan('user:admin', ['email' => 'capo@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse((bool) $user->fresh()?->is_admin);

        $this->artisan('user:admin', ['email' => 'nessuno@example.com'])->assertFailed();
    }
}
