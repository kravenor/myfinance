<?php

namespace App\Console\Commands;

use App\Services\RecurringTransactionRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunRecurringTransactions extends Command
{
    protected $signature = 'recurring:run {--date= : Data di riferimento (Y-m-d), default oggi}';

    protected $description = 'Materializza in Transaction le ricorrenti maturate fino alla data indicata.';

    public function handle(RecurringTransactionRunner $runner): int
    {
        $until = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();

        $count = $runner->run($until);

        $this->info("Generate {$count} transazioni (until {$until->toDateString()}).");

        return self::SUCCESS;
    }
}
