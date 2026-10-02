<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsFromPreferences;
use App\Notifications\Concerns\FormatsForUser;
use App\Notifications\Contracts\Dedupable;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Esito delle rate PAC registrate dal runner per una ricorrente: una notifica per esecuzione,
 * anche se recupera più rate arretrate. Avviso se il prezzo è stimato o se le quote mancano.
 */
class PacInstallmentNotification extends Notification implements Dedupable
{
    use ChannelsFromPreferences, FormatsForUser, Queueable;

    /**
     * @param  array{recurring_id: int, holding: string, count: int, last_date: string, quantity: ?float, price: ?float, currency: string, estimated: bool}  $run
     */
    public function __construct(private readonly array $run) {}

    public function dedupKey(): string
    {
        return "pac:{$this->run['recurring_id']}:{$this->run['last_date']}";
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $r = $this->run;
        $date = $this->userDate($notifiable, $r['last_date']);
        $when = $r['count'] > 1 ? "{$r['count']} rate, l'ultima del {$date}" : "Rata del {$date}";

        if ($r['quantity'] === null) {
            return $this->payload('warning', "Rata PAC da completare: {$r['holding']}",
                "{$when}: registrato solo il movimento di cassa, manca una quotazione per calcolare le quote. Aggiungi l'acquisto in Investimenti.");
        }

        $quantity = rtrim(rtrim(number_format($r['quantity'], 6, ',', '.'), '0'), ',');
        $message = "{$when}: {$quantity} quote a ".Money::format((float) $r['price'], $r['currency']).'.';
        if ($r['estimated']) {
            $message .= ' Prezzo stimato perché non c\'è ancora una quotazione: verificalo con l\'eseguito del broker.';
        }

        return $this->payload($r['estimated'] ? 'warning' : 'info', "Rata PAC registrata: {$r['holding']}", $message);
    }

    /** @return array<string, mixed> */
    private function payload(string $level, string $title, string $message): array
    {
        return ['key' => $this->dedupKey(), 'type' => 'pac', 'level' => $level, 'title' => $title, 'message' => $message, 'url' => '/investments'];
    }
}
