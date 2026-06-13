<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function rate(string $date, string $currency, float $rate): void
    {
        ExchangeRate::query()->create(['date' => $date, 'currency' => $currency, 'rate' => $rate]);
    }

    public function test_cross_currency_transfer_computes_transfer_amount(): void
    {
        $this->rate('2026-05-10', 'USD', 1.10); // 1 EUR = 1.10 USD

        $user = User::factory()->create(['currency' => 'EUR']);
        $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
        $usd = Account::factory()->for($user)->create(['currency' => 'USD']);

        $this->actingAs($user)->postJson('/api/transactions', [
            'account_id' => $eur->id,
            'transfer_account_id' => $usd->id,
            'type' => 'transfer',
            'amount' => 100,
            'occurred_at' => '2026-05-10',
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.transfer_amount', '110.00');
    }

    public function test_same_currency_transfer_sets_transfer_amount_equal_to_amount(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $a = Account::factory()->for($user)->create(['currency' => 'EUR']);
        $b = Account::factory()->for($user)->create(['currency' => 'EUR']);

        $this->actingAs($user)->postJson('/api/transactions', [
            'account_id' => $a->id,
            'transfer_account_id' => $b->id,
            'type' => 'transfer',
            'amount' => 75,
            'occurred_at' => '2026-05-10',
        ])->assertCreated()
            ->assertJsonPath('data.transfer_amount', '75.00');
    }

    public function test_manual_transfer_amount_override_is_respected(): void
    {
        $this->rate('2026-05-10', 'USD', 1.10);

        $user = User::factory()->create(['currency' => 'EUR']);
        $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
        $usd = Account::factory()->for($user)->create(['currency' => 'USD']);

        $this->actingAs($user)->postJson('/api/transactions', [
            'account_id' => $eur->id,
            'transfer_account_id' => $usd->id,
            'type' => 'transfer',
            'amount' => 100,
            'transfer_amount' => 105, // tasso reale applicato dalla banca
            'occurred_at' => '2026-05-10',
        ])->assertCreated()
            ->assertJsonPath('data.transfer_amount', '105.00');
    }

    public function test_transaction_currency_follows_account(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $usd = Account::factory()->for($user)->create(['currency' => 'USD']);

        $this->actingAs($user)->postJson('/api/transactions', [
            'account_id' => $usd->id,
            'type' => 'expense',
            'amount' => 40,
            'occurred_at' => '2026-05-10',
        ])->assertCreated()
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_summary_converts_foreign_currency_to_base(): void
    {
        $this->rate('2026-05-01', 'USD', 1.10); // 1 EUR = 1.10 USD

        $user = User::factory()->create(['currency' => 'EUR']);
        $usd = Account::factory()->for($user)->create(['currency' => 'USD', 'initial_balance' => 0]);

        Transaction::factory()->for($user)->for($usd, 'account')->create([
            'type' => 'income', 'amount' => 110, 'currency' => 'USD', 'occurred_at' => '2026-05-10',
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.base_currency', 'EUR')
            // 110 USD / 1.10 = 100 EUR
            ->assertJsonPath('data.income', '100.00')
            ->assertJsonPath('data.accounts.0.currency', 'USD')
            ->assertJsonPath('data.accounts.0.balance', '110.00')
            ->assertJsonPath('data.accounts.0.balance_base', '100.00')
            ->assertJsonPath('data.net_worth', '100.00');
    }
}
