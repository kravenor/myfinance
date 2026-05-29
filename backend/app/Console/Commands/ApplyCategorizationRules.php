<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CategorizationRuleApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class ApplyCategorizationRules extends Command
{
    protected $signature = 'rules:apply
        {--dry-run : Non scrive nulla, mostra solo il riepilogo}
        {--only-uncategorized=true : Limita alle transazioni senza categoria}
        {--user= : Limita a un user_id specifico (default tutti)}
        {--account= : Limita a un account_id}
        {--from= : Data minima (Y-m-d)}
        {--to= : Data massima (Y-m-d)}';

    protected $description = 'Applica retroattivamente le regole di categorizzazione alle transazioni esistenti.';

    public function handle(CategorizationRuleApplier $applier): int
    {
        $userIds = $this->option('user')
            ? [(int) $this->option('user')]
            : User::query()->pluck('id')->all();

        $dryRun = (bool) $this->option('dry-run');

        foreach ($userIds as $userId) {
            Auth::loginUsingId($userId); // attiva il global scope per-utente

            $result = $applier->run([
                'only_uncategorized' => filter_var($this->option('only-uncategorized'), FILTER_VALIDATE_BOOL),
                'account_id' => $this->option('account') ? (int) $this->option('account') : null,
                'from' => $this->option('from'),
                'to' => $this->option('to'),
            ], $dryRun);

            Auth::logout(); // evita leak di scope tra utenti

            $this->line("User {$userId}: matched={$result['matched']}, updated={$result['updated']}");
            if ($result['by_rule'] !== []) {
                $this->table(
                    ['Regola', 'Conteggio'],
                    array_map(fn (array $r) => [$r['name'], $r['count']], $result['by_rule']),
                );
            }
        }

        if ($dryRun) {
            $this->comment('Dry-run: nessuna modifica scritta.');
        }

        return self::SUCCESS;
    }
}
