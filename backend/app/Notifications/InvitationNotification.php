<?php

namespace App\Notifications;

use App\Support\MailText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Invio sincrono e non in coda: l'admin deve sapere subito se l'email è partita,
 * altrimenti copia il link a mano. Il token non resta nel payload della coda.
 */
class InvitationNotification extends Notification
{
    public function __construct(
        private readonly string $url,
        private readonly string $inviterName,
        private readonly Carbon $expiresAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invito a Finance')
            ->greeting('Ciao!')
            ->line(MailText::escape($this->inviterName).' ti ha invitato a creare un account su Finance.')
            ->action('Crea il tuo account', $this->url)
            ->line('Il link vale una sola volta e scade il '.$this->expiresAt->format('d/m/Y').'.')
            ->line('Se non aspettavi questo invito puoi ignorare l\'email.');
    }
}
