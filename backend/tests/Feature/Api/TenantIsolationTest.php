<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\RecurringTransaction;
use App\Models\SavingsGoal;
use App\Models\Scenario;
use App\Models\ScenarioItem;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NotificationScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * I dati di un altro utente non devono cambiare nessuna risposta: né liste né aggregati
 * (un report scritto con DB::table() sommerebbe tutti senza errori visibili).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const URLS = [
        '/api/accounts',
        '/api/categories',
        '/api/tags',
        '/api/transactions',
        '/api/transactions/export',
        '/api/budgets',
        '/api/budgets/alerts',
        '/api/recurring-transactions',
        '/api/categorization-rules',
        '/api/savings-goals',
        '/api/scenarios',
        '/api/investment-holdings',
        '/api/investments/overview',
        '/api/investments/history',
        '/api/notifications',
        '/api/reports/summary',
        '/api/reports/by-category',
        '/api/reports/by-tag',
        '/api/reports/timeline',
        '/api/reports/net-worth',
        '/api/reports/period-comparison',
        '/api/reports/top-transactions',
        '/api/reports/cash-flow-forecast',
        '/api/reports/expense-forecast',
        '/api/reports/expense-forecast/compare',
    ];

    public function test_other_users_data_does_not_change_any_response(): void
    {
        $this->travelTo('2026-06-15 12:00:00');

        $user = User::factory()->create();
        $this->seedData($user);
        $before = $this->snapshot($user);

        $this->seedData(User::factory()->create());

        $this->assertSame($before, $this->snapshot($user));
    }

    public function test_scanner_refuses_a_user_other_than_the_authenticated_one(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(LogicException::class);
        app(NotificationScanner::class)->scan(User::factory()->create());
    }

    private function seedData(User $user): void
    {
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $tag = Tag::factory()->for($user)->create();

        Budget::factory()->for($user)->for($category)->create(['year' => 2026, 'month' => 6, 'amount' => 50]);
        foreach (['2026-05-20', '2026-06-10'] as $date) {
            Transaction::factory()->for($user)->for($account, 'account')->create([
                'category_id' => $category->id, 'amount' => 400, 'occurred_at' => $date,
            ])->tags()->attach($tag);
        }
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 2000, 'occurred_at' => '2026-06-01',
        ]);

        RecurringTransaction::factory()->for($user)->for($account, 'account')->create();
        SavingsGoal::factory()->for($user)->create();
        ScenarioItem::factory()->for($user)->for(Scenario::factory()->for($user))->create();

        $broker = Account::factory()->for($user)->create(['type' => 'investment']);
        $holding = InvestmentHolding::factory()->for($user)->for($broker, 'account')->create();
        InvestmentTransaction::factory()->for($user)->for($holding, 'holding')->create();

        // Notifiche (budget superato) generate con lo scope dell'utente.
        $this->actingAs($user);
        app(NotificationScanner::class)->scan($user);
        Auth::forgetUser();
    }

    /** @return array<string, string> */
    private function snapshot(User $user): array
    {
        $this->actingAs($user);

        return collect(self::URLS)->mapWithKeys(function (string $url) {
            $response = $this->get($url, ['Accept' => 'application/json'])->assertOk();

            return [$url => $response->baseResponse instanceof StreamedResponse
                ? $response->streamedContent()
                : $response->getContent()];
        })->all();
    }
}
