<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\BudgetThresholdNotification;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['webpush.vapid.public_key' => 'chiave-pubblica-di-test']);
    }

    /** @return array<string, mixed> */
    private function subscription(string $endpoint = self::ENDPOINT): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'], 'content_encoding' => 'aes128gcm'];
    }

    public function test_exposes_the_public_key(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/push-subscriptions/key')
            ->assertOk()
            ->assertJsonPath('data.public_key', 'chiave-pubblica-di-test');
    }

    public function test_stores_a_subscription_and_moves_it_when_another_user_logs_in_on_the_device(): void
    {
        [$first, $second] = User::factory()->count(2)->create();

        $this->actingAs($first)->postJson('/api/push-subscriptions', $this->subscription())->assertNoContent();
        $this->assertSame(1, $first->pushSubscriptions()->count());

        $this->actingAs($second)->postJson('/api/push-subscriptions', $this->subscription())->assertNoContent();
        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(1, $second->pushSubscriptions()->count());
    }

    public function test_rejects_endpoints_that_are_not_known_push_services(): void
    {
        $user = User::factory()->create();

        foreach (['http://fcm.googleapis.com/x', 'https://redis:6379/x', 'https://fcm.googleapis.com.evil.test/x', 'https://127.0.0.1/x'] as $endpoint) {
            $this->actingAs($user)->postJson('/api/push-subscriptions', $this->subscription($endpoint))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('endpoint');
        }
        // Sottodomini consentiti solo dove l'elenco li prevede.
        $this->actingAs($user)->postJson('/api/push-subscriptions', $this->subscription('https://wns2-db5p.notify.windows.com/w/?token=x'))
            ->assertNoContent();
    }

    public function test_deletes_only_own_subscriptions(): void
    {
        [$owner, $other] = User::factory()->count(2)->create();
        $owner->updatePushSubscription(self::ENDPOINT, 'k', 't');

        $this->actingAs($other)->deleteJson('/api/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertSame(1, $owner->pushSubscriptions()->count());

        $this->actingAs($owner)->deleteJson('/api/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertSame(0, $owner->pushSubscriptions()->count());
    }

    public function test_alerts_go_to_push_only_for_subscribed_users_and_reuse_the_in_app_texts(): void
    {
        Notification::fake();
        [$subscribed, $plain] = User::factory()->count(2)->create();
        $subscribed->updatePushSubscription(self::ENDPOINT, 'k', 't');
        $alert = ['budget_id' => 7, 'category_id' => 1, 'category_name' => 'Spesa', 'category_color' => null, 'year' => 2026, 'month' => 5, 'amount' => '100.00', 'spent' => '90.00', 'percent' => 90.0, 'status' => 'warning'];

        $subscribed->notify(new BudgetThresholdNotification($alert));
        $plain->notify(new BudgetThresholdNotification($alert));

        Notification::assertSentTo($subscribed, BudgetThresholdNotification::class, function ($n, array $channels) use ($subscribed) {
            $push = $n->toWebPush($subscribed)->toArray();

            return in_array(WebPushChannel::class, $channels, true)
                && $push['title'] === 'Budget in allerta: Spesa'
                && $push['data'] === ['url' => '/budgets']
                && $push['tag'] === 'budget:warning:7:2026-5';
        });
        Notification::assertSentTo($plain, BudgetThresholdNotification::class, fn ($n, array $channels) => ! in_array(WebPushChannel::class, $channels, true));
    }

    public function test_test_push_requires_an_active_subscription(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/push-subscriptions/test')->assertUnprocessable();

        $user->updatePushSubscription(self::ENDPOINT, 'k', 't');
        $this->actingAs($user)->postJson('/api/push-subscriptions/test')->assertOk();
        Notification::assertSentTo($user, TestPushNotification::class);
    }
}
