<?php

namespace App\Notifications;

use App\Models\SavingsGoal;
use App\Notifications\Contracts\Dedupable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class SavingsGoalRiskNotification extends Notification implements Dedupable
{
    use Queueable;

    public function __construct(
        private readonly SavingsGoal $goal,
        private readonly string $status,
    ) {}

    /**
     * Chiave di dedup: una notifica per (goal, stato, mese corrente).
     */
    public function dedupKey(): string
    {
        return "goal:{$this->status}:{$this->goal->id}:".Carbon::now()->format('Y-m');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('finance.notifications.mail', true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'key' => $this->dedupKey(),
            'type' => 'savings_goal',
            'level' => $this->status,
            'title' => $this->headline(),
            'message' => $this->detail(),
            'url' => '/savings-goals',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->greeting('Obiettivo di risparmio')
            ->line($this->detail())
            ->action('Vai agli obiettivi', url('/savings-goals'));
    }

    private function headline(): string
    {
        $label = $this->status === 'overdue' ? 'scaduto' : 'in ritardo';

        return "Obiettivo {$label}: {$this->goal->name}";
    }

    private function detail(): string
    {
        $saved = $this->goal->getAttribute('saved');
        $target = number_format((float) $this->goal->target_amount, 2, '.', '');

        if ($this->status === 'overdue') {
            return "L'obiettivo «{$this->goal->name}» è scaduto senza raggiungere il target ({$saved} su {$target}).";
        }

        return "L'obiettivo «{$this->goal->name}» è in ritardo sul ritmo previsto ({$saved} su {$target}).";
    }
}
