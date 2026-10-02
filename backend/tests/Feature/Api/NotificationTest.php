<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BudgetThresholdNotification;
use App\Services\NotificationScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Isola il canale database nei test sullo scanner.
        config(['finance.notifications.mail' => false]);
    }

    private function exceededBudget(User $user): void
    {
        $now = Carbon::now();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense', 'name' => 'Spesa']);
        Budget::factory()->for($user)->for($category, 'category')->create([
            'year' => $now->year, 'month' => $now->month, 'amount' => 100,
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->for($category, 'category')->create([
            'type' => 'expense', 'amount' => 150, 'occurred_at' => $now->toDateString(),
        ]);
    }

    public function test_scan_creates_budget_notification(): void
    {
        $user = User::factory()->create();
        $this->exceededBudget($user);
        $this->actingAs($user);

        $sent = app(NotificationScanner::class)->scan($user);

        $this->assertSame(1, $sent);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('budget', $user->notifications()->first()->data['type']);
        $this->assertSame('exceeded', $user->notifications()->first()->data['level']);
    }

    public function test_scan_is_idempotent_for_same_period(): void
    {
        $user = User::factory()->create();
        $this->exceededBudget($user);
        $this->actingAs($user);

        $scanner = app(NotificationScanner::class);
        $scanner->scan($user);
        $secondRun = $scanner->scan($user);

        $this->assertSame(0, $secondRun);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_scan_creates_overdue_goal_notification(): void
    {
        $user = User::factory()->create();
        SavingsGoal::factory()->for($user)->create([
            'status' => 'active',
            'target_amount' => 1000,
            'target_date' => Carbon::now()->subDay()->toDateString(),
        ]);
        $this->actingAs($user);

        $sent = app(NotificationScanner::class)->scan($user);

        $this->assertSame(1, $sent);
        $this->assertSame('savings_goal', $user->notifications()->first()->data['type']);
        $this->assertSame('overdue', $user->notifications()->first()->data['level']);
    }

    public function test_index_returns_notifications_and_unread_count(): void
    {
        $user = User::factory()->create();
        $user->notify(new BudgetThresholdNotification($this->sampleAlert()));

        $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'budget');
    }

    public function test_mark_read_decrements_unread_count(): void
    {
        $user = User::factory()->create();
        $user->notify(new BudgetThresholdNotification($this->sampleAlert()));
        $id = $user->notifications()->first()->id;

        $this->actingAs($user)
            ->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new BudgetThresholdNotification($this->sampleAlert()));

        $this->actingAs($user)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_via_includes_mail_only_when_enabled(): void
    {
        $user = User::factory()->create();
        $notification = new BudgetThresholdNotification($this->sampleAlert());

        config(['finance.notifications.mail' => false]);
        $this->assertSame(['database'], $notification->via($user));

        config(['finance.notifications.mail' => true]);
        $this->assertEqualsCanonicalizing(['database', 'mail'], $notification->via($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAlert(): array
    {
        return [
            'budget_id' => 1,
            'category_id' => 1,
            'category_name' => 'Spesa',
            'category_color' => null,
            'year' => 2026,
            'month' => 6,
            'amount' => '100.00',
            'spent' => '150.00',
            'percent' => 150.0,
            'status' => 'exceeded',
        ];
    }

    public function test_dismissed_notification_disappears_and_is_not_recreated(): void
    {
        $user = User::factory()->create();
        $this->exceededBudget($user);
        $this->actingAs($user);
        app(NotificationScanner::class)->scan($user);
        $id = $user->notifications()->first()->id;

        $this->deleteJson("/api/notifications/{$id}")->assertNoContent();

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        // Prima l'eliminazione cancellava la riga e la scansione successiva la ricreava.
        $this->assertSame(0, app(NotificationScanner::class)->scan($user));
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_in_app_is_written_immediately_while_email_waits_for_the_worker(): void
    {
        config(['finance.notifications.mail' => true, 'queue.default' => 'database']);
        $user = User::factory()->create();
        $alert = ['budget_id' => 1, 'category_id' => 1, 'category_name' => 'Spesa', 'category_color' => null, 'year' => 2026, 'month' => 5, 'amount' => '100.00', 'spent' => '90.00', 'percent' => 90.0, 'status' => 'warning'];

        $user->notifyOnce(new BudgetThresholdNotification($alert));

        $this->assertSame(1, $user->notifications()->count()); // canale database sincrono: la dedup la vede subito
        $this->assertSame(1, DB::table('jobs')->count());        // l'email aspetta il worker
        $this->assertFalse($user->notifyOnce(new BudgetThresholdNotification($alert)));
    }

    public function test_prune_deletes_only_notifications_older_than_the_limit(): void
    {
        $user = User::factory()->create();
        $alert = fn (int $id) => new BudgetThresholdNotification(['budget_id' => $id, 'category_id' => 1, 'category_name' => 'Spesa', 'category_color' => null, 'year' => 2026, 'month' => 5, 'amount' => '100.00', 'spent' => '90.00', 'percent' => 90.0, 'status' => 'warning']);

        $this->travelTo('2026-01-01');
        $user->notifyOnce($alert(1));
        $this->travelTo('2026-08-01'); // 212 giorni dopo la prima
        $user->notifyOnce($alert(2));

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame(['budget:warning:2:2026-5'], $user->notifications()->get()->pluck('data.key')->all());
    }
}
