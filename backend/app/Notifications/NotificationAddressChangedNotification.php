<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avviso di sicurezza al vecchio indirizzo quando cambia quello delle notifiche.
 * Parte anche con le email disattivate nelle preferenze: chi ha cambiato l'indirizzo potrebbe non essere il titolare.
 */
class NotificationAddressChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $newAddress) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return config('finance.notifications.mail', true) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Indirizzo delle notifiche cambiato')
            ->line("Da ora Finance invia le notifiche email a {$this->newAddress}.")
            ->line("Se il cambio non l'hai fatto tu, accedi subito, cambia la password e rimetti il tuo indirizzo nelle Impostazioni.")
            ->action('Apri le Impostazioni', url('/settings'));
    }
}
