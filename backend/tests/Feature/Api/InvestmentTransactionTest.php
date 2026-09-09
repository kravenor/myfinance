<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\User;
use App\Services\HoldingPositionRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InvestmentTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function holding(User $user, array $attributes = []): InvestmentHolding
    {
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        return InvestmentHolding::factory()->for($user)->create(array_merge([
            'account_id' => $account->id,
            'currency' => 'EUR',
            'quantity' => 0,
            'avg_cost' => 0,
            'last_price' => null,
        ], $attributes));
    }

    private function buy(User $user, InvestmentHolding $holding, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson(
            "/api/investment-holdings/{$holding->id}/transactions",
            array_merge(['side' => 'buy', 'fees' => 0], $payload)
        );
    }

    public function test_index_requires_auth(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->getJson("/api/investment-holdings/{$holding->id}/transactions")->assertUnauthorized();
    }

    public function test_successive_buys_build_the_weighted_average_position(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40])
            ->assertCreated()
            ->assertJsonPath('data.cash_flow', '400.00');

        $this->buy($user, $holding, ['occurred_at' => '2026-02-02', 'quantity' => 5, 'price' => 52])
            ->assertCreated();

        $holding->refresh();
        $this->assertSame('15.00000000', $holding->quantity);
        $this->assertSame('44.00000000', $holding->avg_cost);
        $this->assertSame('660.00', $holding->net_invested);
        $this->assertSame('0.00', $holding->realized_pl);
    }

    public function test_fees_are_part_of_the_cost(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40, 'fees' => 5])
            ->assertCreated();

        $holding->refresh();
        $this->assertSame('40.50000000', $holding->avg_cost);
        $this->assertSame('405.00', $holding->net_invested);
    }

    public function test_sell_realizes_pl_and_leaves_the_average_cost_untouched(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40]);
        $this->buy($user, $holding, ['occurred_at' => '2026-02-02', 'quantity' => 5, 'price' => 52]);

        $this->actingAs($user)->postJson("/api/investment-holdings/{$holding->id}/transactions", [
            'side' => 'sell',
            'occurred_at' => '2026-03-02',
            'quantity' => 5,
            'price' => 60,
        ])->assertCreated();

        $holding->refresh();
        $this->assertSame('10.00000000', $holding->quantity);
        // Il costo medio non si muove su una vendita: cambia solo la quantità.
        $this->assertSame('44.00000000', $holding->avg_cost);
        // 5 × 60 incassati contro 5 × 44 di costo scaricato.
        $this->assertSame('80.00', $holding->realized_pl);
        $this->assertSame('360.00', $holding->net_invested);
    }

    public function test_cannot_sell_more_than_held(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40]);

        $this->actingAs($user)->postJson("/api/investment-holdings/{$holding->id}/transactions", [
            'side' => 'sell',
            'occurred_at' => '2026-02-02',
            'quantity' => 11,
            'price' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $holding->refresh();
        $this->assertSame('10.00000000', $holding->quantity);
        $this->assertDatabaseCount('investment_transactions', 1);
    }

    public function test_a_backdated_sell_is_rejected_when_the_shares_were_not_yet_held(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->buy($user, $holding, ['occurred_at' => '2026-06-01', 'quantity' => 10, 'price' => 40]);

        $this->actingAs($user)->postJson("/api/investment-holdings/{$holding->id}/transactions", [
            'side' => 'sell',
            'occurred_at' => '2026-01-01',
            'quantity' => 5,
            'price' => 60,
        ])->assertUnprocessable();
    }

    public function test_deleting_a_movement_recalculates_the_position(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40]);
        $second = $this->buy($user, $holding, ['occurred_at' => '2026-02-02', 'quantity' => 5, 'price' => 52])
            ->json('data.id');

        $this->actingAs($user)
            ->deleteJson("/api/investment-holdings/{$holding->id}/transactions/{$second}")
            ->assertNoContent();

        $holding->refresh();
        $this->assertSame('10.00000000', $holding->quantity);
        $this->assertSame('40.00000000', $holding->avg_cost);
        $this->assertSame('400.00', $holding->net_invested);
    }

    public function test_updating_a_movement_recalculates_the_position(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        $id = $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40])
            ->json('data.id');

        $this->actingAs($user)
            ->patchJson("/api/investment-holdings/{$holding->id}/transactions/{$id}", ['price' => 45])
            ->assertOk();

        $holding->refresh();
        $this->assertSame('45.00000000', $holding->avg_cost);
        $this->assertSame('450.00', $holding->net_invested);
    }

    public function test_creating_a_holding_with_a_position_records_an_opening_movement(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['type' => 'investment']);

        $id = $this->actingAs($user)->postJson('/api/investment-holdings', [
            'account_id' => $account->id,
            'name' => 'Vanguard FTSE All-World',
            'asset_type' => 'etf',
            'quantity' => 12,
            'avg_cost' => 100,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('investment_transactions', [
            'investment_holding_id' => $id,
            'side' => 'buy',
            'notes' => 'Posizione iniziale',
        ]);
        $this->assertSame('1200.00', InvestmentHolding::find($id)->net_invested);
    }

    public function test_holding_update_cannot_overwrite_the_derived_position(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);
        $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40]);

        $this->actingAs($user)->patchJson("/api/investment-holdings/{$holding->id}", [
            'name' => 'Rinominato',
            'quantity' => 999,
            'avg_cost' => 1,
        ])->assertOk();

        $holding->refresh();
        $this->assertSame('Rinominato', $holding->name);
        $this->assertSame('10.00000000', $holding->quantity);
        $this->assertSame('40.00000000', $holding->avg_cost);
    }

    public function test_movements_of_another_user_are_not_reachable(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $theirHolding = $this->holding($other);

        $this->actingAs($mine)
            ->getJson("/api/investment-holdings/{$theirHolding->id}/transactions")
            ->assertNotFound();

        $this->actingAs($mine)->postJson("/api/investment-holdings/{$theirHolding->id}/transactions", [
            'side' => 'buy',
            'occurred_at' => '2026-01-02',
            'quantity' => 1,
            'price' => 1,
        ])->assertNotFound();
    }

    public function test_a_movement_cannot_be_addressed_through_another_holding(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);
        $otherHolding = $this->holding($user);

        $id = $this->buy($user, $holding, ['occurred_at' => '2026-01-02', 'quantity' => 10, 'price' => 40])
            ->json('data.id');

        $this->actingAs($user)
            ->deleteJson("/api/investment-holdings/{$otherHolding->id}/transactions/{$id}")
            ->assertNotFound();

        $this->assertDatabaseCount('investment_transactions', 1);
    }

    public function test_backfill_turns_an_existing_position_into_an_opening_movement(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user, ['quantity' => 12, 'avg_cost' => 100]);

        // Stato pre-registro: la posizione esiste solo come fotografia sull'holding.
        DB::table('investment_transactions')->delete();
        DB::table('investment_holdings')->update(['net_invested' => 0, 'realized_pl' => 0]);

        $migration = require base_path('database/migrations/2026_09_09_120100_backfill_investment_transactions_from_holdings.php');
        $migration->up();

        $this->assertDatabaseHas('investment_transactions', [
            'investment_holding_id' => $holding->id,
            'user_id' => $user->id,
            'side' => 'buy',
            'quantity' => 12,
            'price' => 100,
        ]);
        $this->assertSame('1200.00', $holding->fresh()->net_invested);

        // E il ricalcolo sul registro appena creato restituisce la posizione di partenza.
        app(HoldingPositionRecalculator::class)->recalculate($holding);
        $holding->refresh();
        $this->assertSame('12.00000000', $holding->quantity);
        $this->assertSame('100.00000000', $holding->avg_cost);
    }

    public function test_index_lists_the_movements_newest_first(): void
    {
        $user = User::factory()->create();
        $holding = $this->holding($user);

        InvestmentTransaction::factory()->for($user)->create([
            'investment_holding_id' => $holding->id,
            'occurred_at' => '2026-01-02',
            'quantity' => 1,
            'price' => 10,
        ]);
        InvestmentTransaction::factory()->for($user)->create([
            'investment_holding_id' => $holding->id,
            'occurred_at' => '2026-05-02',
            'quantity' => 1,
            'price' => 10,
        ]);

        $this->actingAs($user)
            ->getJson("/api/investment-holdings/{$holding->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.occurred_at', '2026-05-02')
            ->assertJsonPath('data.1.occurred_at', '2026-01-02');
    }
}
