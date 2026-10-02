<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Concerns\ChannelsFromPreferences;
use App\Notifications\Concerns\FormatsForUser;
use App\Notifications\Contracts\Dedupable;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Uscita oltre la soglia scelta in Impostazioni (importo già convertito nella valuta base). */
class LargeExpenseNotification extends Notification implements Dedupable, ShouldQueue
{
    use ChannelsFromPreferences, FormatsForUser, Queueable;

    public function __construct(
        private readonly Transaction $transaction,
        private readonly float $amountBase,
        private readonly string $baseCurrency,
        private readonly ?string $categoryName,
    ) {}

    public function dedupKey(): string
    {
        return "large-expense:{$this->transaction->id}";
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $label = $this->transaction->description ?: ($this->categoryName ?? 'Uscita');
        $amount = Money::format($this->amountBase, $this->baseCurrency);

        return [
            'key' => $this->dedupKey(),
            'type' => 'large_expense',
            'level' => 'info',
            'title' => "Spesa importante: {$amount}",
            'message' => "{$label}, il {$this->userDate($notifiable, $this->transaction->occurred_at)}.",
            'url' => '/transactions',
        ];
    }
}
