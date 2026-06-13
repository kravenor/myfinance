<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsFromPreferences;
use App\Notifications\Contracts\Dedupable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetThresholdNotification extends Notification implements Dedupable
{
    use ChannelsFromPreferences, Queueable;

    /**
     * @param  array{budget_id: int, category_id: int, category_name: ?string, category_color: ?string, year: int, month: int, amount: string, spent: string, percent: float, status: string}  $alert
     */
    public function __construct(private readonly array $alert) {}

    /**
     * Chiave di dedup: una notifica per (budget, stato, mese).
     */
    public function dedupKey(): string
    {
        return "budget:{$this->alert['status']}:{$this->alert['budget_id']}:{$this->alert['year']}-{$this->alert['month']}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $a = $this->alert;
        $category = $a['category_name'] ?? 'categoria';
        $verb = $a['status'] === 'exceeded' ? 'sforato' : 'in allerta';

        return [
            'key' => $this->dedupKey(),
            'type' => 'budget',
            'level' => $a['status'],
            'title' => "Budget {$verb}: {$category}",
            'message' => "Spesi {$a['spent']} su {$a['amount']} ({$a['percent']}%) per {$category}.",
            'url' => '/budgets',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->alert;
        $category = $a['category_name'] ?? 'categoria';
        $verb = $a['status'] === 'exceeded' ? 'sforato' : 'in allerta';

        return (new MailMessage)
            ->subject("Budget {$verb}: {$category}")
            ->greeting('Avviso budget')
            ->line("Il budget per «{$category}» è {$verb}.")
            ->line("Spesi {$a['spent']} su {$a['amount']} ({$a['percent']}%).")
            ->action('Vai ai budget', url('/budgets'));
    }
}
