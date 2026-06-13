<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class FetchExchangeRates extends Command
{
    protected $signature = 'exchange-rates:fetch
        {--backfill : Scarica lo storico dalla data finance.rates.history_start a oggi}
        {--from= : Data iniziale (Y-m-d) per il backfill, sovrascrive history_start}
        {--to= : Data finale (Y-m-d) per il backfill, default oggi}';

    protected $description = 'Scarica i tassi di cambio (Frankfurter/BCE) e li salva in exchange_rates.';

    public function handle(ExchangeRateProvider $provider): int
    {
        try {
            if ($this->option('backfill') || $this->option('from')) {
                $from = Carbon::parse($this->option('from') ?: config('finance.rates.history_start'));
                $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::now();

                $count = $provider->fetchRange($from, $to);
                $this->info("Backfill tassi {$from->toDateString()} → {$to->toDateString()}: {$count} righe.");
            } else {
                $count = $provider->fetchLatest();
                $this->info("Tassi aggiornati: {$count} righe.");
            }
        } catch (Throwable $e) {
            $this->error("Aggiornamento tassi fallito: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
