<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DisableTwoFactor extends Command
{
    protected $signature = 'user:two-factor-disable {email}';

    protected $description = 'Disattiva la verifica in due passaggi di un utente (telefono e codici di recupero persi).';

    public function handle(TwoFactorAuthenticator $twoFactor): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('Utente non trovato.');

            return self::FAILURE;
        }

        $twoFactor->disable($user);
        Log::warning('Verifica in due passaggi disattivata da console', ['user_id' => $user->id]);
        $this->info("Verifica in due passaggi disattivata per {$user->email}.");

        return self::SUCCESS;
    }
}
