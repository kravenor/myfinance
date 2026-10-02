<?php

use App\Console\Commands\BackfillInstrumentPrices;
use App\Console\Commands\FetchExchangeRates;
use App\Console\Commands\FetchInstrumentPrices;
use App\Console\Commands\RunRecurringTransactions;
use App\Console\Commands\ScanNotifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RunRecurringTransactions::class)->dailyAt('02:00');
Schedule::command(FetchExchangeRates::class)->dailyAt('06:00');
Schedule::command(FetchInstrumentPrices::class)->dailyAt('06:30');
// Dopo il fetch giornaliero: riempie solo i buchi (holding nuovi, PAC inseriti a posteriori).
Schedule::command(BackfillInstrumentPrices::class)->dailyAt('06:40');
Schedule::command(ScanNotifications::class)->dailyAt('07:00');
