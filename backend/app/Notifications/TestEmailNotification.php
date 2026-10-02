<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prova dalle Impostazioni: solo email, inviata subito (notifyNow) per vedere l'esito. */
class TestEmailNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Email di prova di Finance')
            ->line('Se leggi questo messaggio, le email delle notifiche arrivano correttamente.')
            ->action('Apri Finance', url('/settings'));
    }
}
