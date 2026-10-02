<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Prova dalle Impostazioni: solo push, non finisce nella lista in-app. */
class TestPushNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Notifiche attive')
            ->body('Questo dispositivo riceverà gli avvisi di Finance.')
            ->icon('/icon-192.png')
            ->badge('/icon-192.png')
            ->tag('push-test')
            ->data(['url' => '/notifications']);
    }
}
