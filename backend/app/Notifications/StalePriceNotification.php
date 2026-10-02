<?php

namespace App\Notifications;

use App\Models\InvestmentHolding;
use App\Notifications\Concerns\ChannelsFromPreferences;
use App\Notifications\Concerns\FormatsForUser;
use App\Notifications\Contracts\Dedupable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Strumento in portafoglio senza quotazioni recenti: provider rotto o simbolo sbagliato. */
class StalePriceNotification extends Notification implements Dedupable, ShouldQueue
{
    use ChannelsFromPreferences, FormatsForUser, Queueable;

    public function __construct(private readonly InvestmentHolding $holding, private readonly ?string $lastAsOf) {}

    /** Una per episodio: se arriva una quotazione e poi si ferma di nuovo, nuova notifica. */
    public function dedupKey(): string
    {
        return "price-stale:{$this->holding->id}:".($this->lastAsOf ?? 'none');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $symbol = $this->holding->symbol;
        $message = $this->lastAsOf
            ? "L'ultima quotazione automatica è del {$this->userDate($notifiable, $this->lastAsOf)} (simbolo {$symbol}): il valore in portafoglio potrebbe non essere aggiornato. Controlla il simbolo in Investimenti."
            : "Nessuna quotazione automatica per il simbolo {$symbol}: controllalo in Investimenti.";

        return [
            'key' => $this->dedupKey(),
            'type' => 'stale_price',
            'level' => 'warning',
            'title' => "Quotazione ferma: {$this->holding->name}",
            'message' => $message,
            'url' => '/investments',
        ];
    }
}
