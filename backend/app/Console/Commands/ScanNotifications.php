<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class ScanNotifications extends Command
{
    protected $signature = 'notifications:scan {--user= : Limita a un user_id specifico}';

    protected $description = 'Genera notifiche per budget sforati/in allerta e obiettivi di risparmio a rischio.';

    public function handle(NotificationScanner $scanner): int
    {
        $userIds = $this->option('user')
            ? [(int) $this->option('user')]
            : User::query()->pluck('id')->all();

        $total = 0;

        foreach ($userIds as $userId) {
            Auth::loginUsingId($userId); // attiva il global scope per-utente

            /** @var User $user */
            $user = Auth::user();
            $sent = $scanner->scan($user);
            $total += $sent;

            Auth::logout(); // evita leak di scope tra utenti

            if ($sent > 0) {
                $this->line("User {$userId}: {$sent} notifiche inviate.");
            }
        }

        $this->info("Totale notifiche inviate: {$total}.");

        return self::SUCCESS;
    }
}
