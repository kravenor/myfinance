<?php

namespace App\Console\Commands;

use App\Services\InvestmentPriceFetcher;
use Illuminate\Console\Command;
use Throwable;

class BackfillInstrumentPrices extends Command
{
    protected $signature = 'prices:backfill
        {--symbol=* : Limita ai simboli indicati (default: tutti gli holding)}
        {--force : Riscarica anche i simboli che hanno già lo storico}';

    protected $description = 'Scarica lo storico mensile delle quotazioni dal primo movimento di ogni strumento (solo provider che lo offrono).';

    public function handle(InvestmentPriceFetcher $fetcher): int
    {
        try {
            /** @var list<string> $symbols */
            $symbols = (array) $this->option('symbol');
            $count = $fetcher->backfill($symbols, (bool) $this->option('force'));
            $this->info("Storico quotazioni: {$count} righe.");
        } catch (Throwable $e) {
            $this->error("Storico quotazioni fallito: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
