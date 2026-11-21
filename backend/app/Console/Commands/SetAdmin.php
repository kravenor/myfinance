<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Console\Command;

class SetAdmin extends Command
{
    protected $signature = 'user:admin {email} {--revoke : Toglie il ruolo invece di assegnarlo}';

    protected $description = 'Assegna (o toglie con --revoke) il ruolo admin, che permette di invitare nuovi utenti.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('Utente non trovato.');

            return self::FAILURE;
        }

        $admin = ! $this->option('revoke');
        // is_admin non è fillable: si assegna solo da qui, mai da una richiesta HTTP.
        $user->forceFill(['is_admin' => $admin])->save();
        SecurityLog::record($admin ? 'admin.granted_from_console' : 'admin.revoked_from_console', $user->id);
        $this->info($admin ? "{$user->email} ora è admin." : "{$user->email} non è più admin.");

        return self::SUCCESS;
    }
}
