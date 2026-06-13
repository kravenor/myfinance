<?php

namespace App\Notifications\Concerns;

use App\Models\User;

trait ChannelsFromPreferences
{
    /**
     * Canali: database sempre attivo; mail se il kill-switch globale è on e
     * l'utente ha abilitato le email.
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

        return $channels;
    }
}
