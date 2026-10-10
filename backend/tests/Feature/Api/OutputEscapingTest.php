<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LargeExpenseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutputEscapingTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_neutralizes_spreadsheet_formulas(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['name' => '@Conto']);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'description' => '=HYPERLINK("https://evil.example";"Apri")',
            'notes' => 'Nota normale',
        ]);

        $body = $this->actingAs($user)->get('/api/transactions/export')->streamedContent();

        $this->assertStringContainsString('"\'=HYPERLINK(""https://evil.example"";""Apri"")"', $body);
        $this->assertStringContainsString("'@Conto", $body);
        // Il testo normale resta com'è.
        $this->assertStringContainsString(',"Nota normale",', $body);
    }

    public function test_notification_email_does_not_turn_descriptions_into_links(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $tx = Transaction::factory()->for($user)->for($account, 'account')->create([
            'description' => '[Verifica il conto](https://phish.example)',
        ]);

        $html = (string) (new LargeExpenseNotification($tx, 500, 'EUR', null))->toMail($user)->render();

        $this->assertStringNotContainsString('href="https://phish.example"', $html);
    }

    public function test_patching_only_the_pattern_of_a_regex_rule_is_validated(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $rule = CategorizationRule::factory()->for($user)->create([
            'category_id' => $category->id,
            'match_type' => 'regex',
            'pattern' => '^ESSELUNGA',
        ]);

        $this->actingAs($user)->patchJson("/api/categorization-rules/{$rule->id}", ['pattern' => '(('])
            ->assertJsonValidationErrors('pattern');
        $this->assertSame('^ESSELUNGA', $rule->fresh()->pattern);
    }
}
