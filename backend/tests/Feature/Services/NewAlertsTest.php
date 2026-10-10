<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\MonthlySummaryNotification;
use App\Notifications\TestEmailNotification;
use App\Services\RecurringTransactionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// Avvisi del passo 3: rata PAC, quotazioni ferme, spesa importante, riepilogo mensile.
class NewAlertsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $prefs */
    private function user(array $prefs = []): User
    {
        return User::factory()->create(['currency' => 'EUR', 'notification_preferences' => $prefs ?: null]);
    }

    private function holding(User $user, array $attributes = []): InvestmentHolding
    {
        return InvestmentHolding::factory()->for($user)->create(array_merge([
            'account_id' => Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR'])->id,
            'currency' => 'EUR', 'asset_type' => 'etf', 'symbol' => 'VWCE.MI', 'name' => 'VWCE', 'quantity' => 0, 'avg_cost' => 0, 'last_price' => null,
        ], $attributes));
    }

    /** @return list<array<string, mixed>> */
    private function alerts(User $user, string $type): array
    {
        return $user->notifications()->get()->pluck('data')->where('type', $type)->values()->all();
    }

    public function test_pac_run_notifies_once_with_quantity_or_flags_an_estimated_price(): void
    {
        $user = $this->user();
        $holding = $this->holding($user);
        InstrumentPrice::create(['provider' => 'yahoo', 'symbol' => 'VWCE.MI', 'currency' => 'EUR', 'price' => 50, 'as_of' => '2026-01-01']);
        RecurringTransaction::factory()->for($user)->for(Account::factory()->for($user), 'account')->create([
            'cadence' => 'monthly', 'interval' => 1, 'starts_on' => '2026-01-01', 'next_run_at' => '2026-01-01',
            'amount' => 100, 'currency' => 'EUR', 'investment_holding_id' => $holding->id,
        ]);

        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-02-15'));

        $pac = $this->alerts($user, 'pac');
        $this->assertCount(1, $pac); // due rate arretrate, una notifica
        $this->assertSame('info', $pac[0]['level']);
        $this->assertStringContainsString('2 rate', $pac[0]['message']);
        $this->assertStringContainsString('2 quote a 50,00', $pac[0]['message']); // poi spazio non separabile e €

        // Senza nessuna quotazione: prezzo manuale come ripiego, avviso da verificare.
        $other = $this->user();
        $estimated = $this->holding($other, ['symbol' => 'NOQUOTE.MI', 'last_price' => 20]);
        RecurringTransaction::factory()->for($other)->for(Account::factory()->for($other), 'account')->create([
            'cadence' => 'monthly', 'interval' => 1, 'starts_on' => '2026-02-01', 'next_run_at' => '2026-02-01',
            'amount' => 100, 'currency' => 'EUR', 'investment_holding_id' => $estimated->id,
        ]);
        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-02-15'));
        $this->assertSame('warning', $this->alerts($other, 'pac')[0]['level']);
    }

    public function test_pac_alert_respects_the_preference(): void
    {
        $user = $this->user(['pac' => false]);
        $holding = $this->holding($user, ['last_price' => 10]);
        RecurringTransaction::factory()->for($user)->for(Account::factory()->for($user), 'account')->create([
            'cadence' => 'monthly', 'interval' => 1, 'starts_on' => '2026-02-01', 'next_run_at' => '2026-02-01',
            'amount' => 100, 'currency' => 'EUR', 'investment_holding_id' => $holding->id,
        ]);

        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-02-15'));

        $this->assertSame([], $this->alerts($user, 'pac'));
    }

    public function test_stale_prices_only_for_held_auto_priced_instruments(): void
    {
        $this->travelTo('2026-05-20');
        $user = $this->user();
        $stale = $this->holding($user, ['symbol' => 'OLD.MI', 'name' => 'Fermo', 'quantity' => 5]);
        $fresh = $this->holding($user, ['symbol' => 'NEW.MI', 'quantity' => 5]);
        $this->holding($user, ['symbol' => 'SOLD.MI', 'quantity' => 0]);
        $this->holding($user, ['symbol' => 'MANUAL', 'asset_type' => 'other', 'quantity' => 5]);
        $this->holding($user, ['symbol' => 'JUST.MI', 'quantity' => 5]); // creato oggi, mai quotato
        InstrumentPrice::create(['provider' => 'yahoo', 'symbol' => 'OLD.MI', 'currency' => 'EUR', 'price' => 10, 'as_of' => '2026-05-05']);
        InstrumentPrice::create(['provider' => 'yahoo', 'symbol' => 'NEW.MI', 'currency' => 'EUR', 'price' => 10, 'as_of' => '2026-05-19']);

        $this->artisan('notifications:scan')->assertSuccessful();
        $this->artisan('notifications:scan')->assertSuccessful();

        $alerts = $this->alerts($user, 'stale_price');
        $this->assertCount(1, $alerts);
        $this->assertSame("price-stale:{$stale->id}:2026-05-05", $alerts[0]['key']);
        $this->assertStringContainsString('05/05/2026', $alerts[0]['message']);
        $this->assertNotSame($fresh->id, $stale->id);
    }

    public function test_large_expense_over_threshold_only_when_enabled_and_recent(): void
    {
        $this->travelTo('2026-05-20 10:00');
        $user = $this->user(['large_expense' => true, 'large_expense_threshold' => 500]);
        $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
        $category = Category::factory()->for($user)->create(['type' => 'expense', 'name' => 'Casa']);
        $big = Transaction::factory()->for($user)->for($account)->for($category)->create(['type' => 'expense', 'amount' => 650, 'currency' => 'EUR', 'occurred_at' => '2026-05-19', 'description' => null]);
        Transaction::factory()->for($user)->for($account)->create(['type' => 'expense', 'amount' => 400, 'currency' => 'EUR', 'occurred_at' => '2026-05-19']);
        Transaction::factory()->for($user)->for($account)->create(['type' => 'expense', 'amount' => 900, 'currency' => 'EUR', 'occurred_at' => '2026-03-01']); // import di un estratto vecchio

        $this->artisan('notifications:scan')->assertSuccessful();

        $alerts = $this->alerts($user, 'large_expense');
        $this->assertCount(1, $alerts);
        $this->assertSame("large-expense:{$big->id}", $alerts[0]['key']);
        $this->assertSame("Spesa importante: 650,00\u{a0}€", $alerts[0]['title']);
        $this->assertSame('Casa, il 19/05/2026.', $alerts[0]['message']);

        $off = $this->user();
        Transaction::factory()->for($off)->for(Account::factory()->for($off))->create(['type' => 'expense', 'amount' => 5000, 'currency' => 'EUR', 'occurred_at' => '2026-05-19']);
        $this->artisan('notifications:scan')->assertSuccessful();
        $this->assertSame([], $this->alerts($off, 'large_expense'));
    }

    public function test_monthly_summary_in_the_first_days_of_the_month_once(): void
    {
        $user = $this->user();
        $account = Account::factory()->for($user)->create(['currency' => 'EUR']);
        Transaction::factory()->for($user)->for($account)->create(['type' => 'income', 'amount' => 2000, 'currency' => 'EUR', 'occurred_at' => '2026-05-05']);
        Transaction::factory()->for($user)->for($account)->create(['type' => 'expense', 'amount' => 1500, 'currency' => 'EUR', 'occurred_at' => '2026-05-10']);

        $this->travelTo('2026-05-25');
        $this->artisan('notifications:scan')->assertSuccessful();
        $this->assertSame([], $this->alerts($user, 'monthly_summary'));

        $this->travelTo('2026-06-02');
        $this->artisan('notifications:scan')->assertSuccessful();
        $this->artisan('notifications:scan')->assertSuccessful();

        $summary = $this->alerts($user, 'monthly_summary');
        $this->assertCount(1, $summary);
        $this->assertSame('Riepilogo di Maggio 2026', $summary[0]['title']);
        $this->assertSame("Entrate 2.000,00\u{a0}€, uscite 1.500,00\u{a0}€, risparmio 500,00\u{a0}€.", $summary[0]['message']);
        $this->assertSame('/reports?tab=trend&period=custom&from=2026-05-01&to=2026-05-31', $summary[0]['url']);
    }

    public function test_monthly_summary_email_colors_income_and_expense(): void
    {
        $user = User::factory()->create(['name' => 'Mario']);
        $html = (string) (new MonthlySummaryNotification([
            'label' => '2026-05', 'from' => '2026-05-01', 'to' => '2026-05-31',
            'income' => '2000', 'expense' => '2500', 'net' => '-500', 'currency' => 'EUR', 'expense_pct' => '12.4',
        ]))->toMail($user)->render();

        $this->assertStringContainsString('Ciao Mario,', $html);
        $this->assertStringContainsString("color: #15803d; font-weight: bold;\">2.000,00\u{a0}€", $html);
        $this->assertStringContainsString("color: #b91c1c; font-weight: bold;\">2.500,00\u{a0}€", $html);
        $this->assertStringContainsString("color: #b91c1c; font-weight: bold;\">-500,00\u{a0}€", $html);
        $this->assertStringContainsString('Uscite in aumento del 12% sul mese prima.', $html);
        $this->assertStringNotContainsString('Regards', $html);
    }

    public function test_email_header_embeds_the_logo_inline(): void
    {
        Notification::route('mail', 'mario@example.test')->notifyNow(new TestEmailNotification, ['mail']);

        $email = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $logo = collect($email->getAttachments())->first(fn ($part) => $part->getDisposition() === 'inline');

        $this->assertNotNull($logo);
        $this->assertStringContainsString('src="cid:'.$logo->getContentId().'"', $email->getHtmlBody());
    }
}
