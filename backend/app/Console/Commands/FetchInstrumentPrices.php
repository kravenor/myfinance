<?php

namespace App\Console\Commands;

use App\Services\InvestmentPriceFetcher;
use Illuminate\Console\Command;
use Throwable;

class FetchInstrumentPrices extends Command
{
    protected $signature = 'prices:fetch
        {--symbol=* : Limita ai simboli indicati (default: tutti gli holding)}';

    protected $description = 'Scarica le quotazioni più recenti degli strumenti e le salva in instrument_prices.';

    public function handle(InvestmentPriceFetcher $fetcher): int
    {
        try {
            /** @var list<string> $symbols */
            $symbols = (array) $this->option('symbol');
            $count = $fetcher->fetchLatest($symbols);
            $this->info("Quotazioni aggiornate: {$count} righe.");
        } catch (Throwable $e) {
            $this->error("Aggiornamento quotazioni fallito: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
