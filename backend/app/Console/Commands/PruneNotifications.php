<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=180 : Età minima in giorni delle notifiche da cancellare}';

    protected $description = 'Cancella le notifiche più vecchie di N giorni (lette, non lette e nascoste).';

    public function handle(): int
    {
        // Le chiavi di dedup contengono il periodo (mese, data, id): oltre 180 giorni non servono più.
        $days = max(1, (int) $this->option('days'));
        $deleted = DatabaseNotification::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Notifiche cancellate: {$deleted}.");

        return self::SUCCESS;
    }
}
