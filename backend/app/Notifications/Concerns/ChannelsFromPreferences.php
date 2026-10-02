<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

trait ChannelsFromPreferences
{
    /**
     * Canali: database sempre attivo; mail se il kill-switch globale è on e
     * l'utente ha abilitato le email; push se le chiavi VAPID ci sono e
     * l'utente l'ha attivata su almeno un dispositivo.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $emailEnabled = $notifiable instanceof User
            ? (bool) $notifiable->notificationPreference('email')
            : true;

        if (config('finance.notifications.mail', true) && $emailEnabled) {
            $channels[] = 'mail';
        }

        if ($notifiable instanceof User && config('webpush.vapid.public_key') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /** Push con gli stessi testi della notifica in-app; il tag evita doppioni sul dispositivo. */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        $data = $this->toArray($notifiable);

        return (new WebPushMessage)
            ->title($data['title'])
            ->body($data['message'])
            ->icon('/icon-192.png')
            ->badge('/icon-192.png')
            ->tag($data['key'])
            ->data(['url' => $data['url']]);
    }
}
