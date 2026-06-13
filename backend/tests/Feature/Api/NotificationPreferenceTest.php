<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BudgetThresholdNotification;
use App\Services\NotificationScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function sampleAlert(): array
    {
        return [
            'budget_id' => 1, 'category_id' => 1, 'category_name' => 'Spesa', 'category_color' => null,
            'year' => 2026, 'month' => 6, 'amount' => '100.00', 'spent' => '150.00', 'percent' => 150.0, 'status' => 'exceeded',
        ];
    }

    private function budgetSpending(User $user, float $amount, float $spent): void
    {
        $now = Carbon::now();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->for($category, 'category')->create([
            'year' => $now->year, 'month' => $now->month, 'amount' => $amount,
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->for($category, 'category')->create([
            'type' => 'expense', 'amount' => $spent, 'occurred_at' => $now->toDateString(),
        ]);
    }

    public function test_me_includes_default_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.notification_preferences.email', true)
            ->assertJsonPath('data.notification_preferences.budget_threshold', 80);
    }

    public function test_update_merges_and_persists_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/notification-preferences', ['email' => false, 'budget_threshold' => 70])
            ->assertOk()
            ->assertJsonPath('data.email', false)
            ->assertJsonPath('data.budget_threshold', 70)
            ->assertJsonPath('data.savings_goals', true); // default preservato

        $this->assertFalse($user->fresh()->notificationPreference('email'));
    }

    public function test_update_validates_threshold_and_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/notification-preferences', ['budget_threshold' => 150, 'email_address' => 'nope'])
            ->assertJsonValidationErrors(['budget_threshold', 'email_address']);
    }

    public function test_via_respects_email_preference(): void
    {
        config(['finance.notifications.mail' => true]);
        $notification = new BudgetThresholdNotification($this->sampleAlert());

        $off = User::factory()->create(['notification_preferences' => ['email' => false]]);
        $this->assertSame(['database'], $notification->via($off));

        $on = User::factory()->create(['notification_preferences' => ['email' => true]]);
        $this->assertEqualsCanonicalizing(['database', 'mail'], $notification->via($on));
    }

    public function test_route_notification_for_mail_uses_custom_address(): void
    {
        $notification = new BudgetThresholdNotification($this->sampleAlert());

        $custom = User::factory()->create(['notification_preferences' => ['email_address' => 'custom@example.test']]);
        $this->assertSame('custom@example.test', $custom->routeNotificationForMail($notification));

        $plain = User::factory()->create(['email' => 'account@example.test']);
        $this->assertSame('account@example.test', $plain->routeNotificationForMail($notification));
    }

    public function test_scanner_skips_disabled_type(): void
    {
        config(['finance.notifications.mail' => false]);
        $user = User::factory()->create(['notification_preferences' => ['budget' => false]]);
        $this->budgetSpending($user, 100, 150); // sforato, ma budget disabilitato
        $this->actingAs($user);

        app(NotificationScanner::class)->scan($user);

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_scanner_respects_custom_budget_threshold(): void
    {
        config(['finance.notifications.mail' => false]);
        // soglia 50%: una spesa al 60% è "warning" (sarebbe "ok" alla soglia di default 80%)
        $user = User::factory()->create(['notification_preferences' => ['budget_threshold' => 50, 'savings_goals' => false]]);
        $this->budgetSpending($user, 100, 60);
        $this->actingAs($user);

        app(NotificationScanner::class)->scan($user);

        $this->assertSame(1, $user->notifications()->where('data->level', 'warning')->count());
    }
}
