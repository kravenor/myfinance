<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Le traduzioni in lang/it arrivano al client: messaggi e nomi dei campi in italiano.
class ItalianMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('it');
    }

    public function test_validation_errors_use_italian_messages_and_attribute_names(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/transactions', ['type' => 'expense'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Il campo importo è obbligatorio.')
            ->assertJsonPath('errors.account_id.0', 'Il campo conto è obbligatorio.');
    }

    public function test_failed_login_message_is_italian(): void
    {
        User::factory()->create(['email' => 'mario@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'mario@example.test', 'password' => 'sbagliata'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email o password non corrette.');
    }
}
