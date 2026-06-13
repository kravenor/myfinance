<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Valuta pivot
    |--------------------------------------------------------------------------
    | Valuta rispetto alla quale sono memorizzati i tassi in `exchange_rates`
    | (1 unità pivot = `rate` unità della valuta quotata). Frankfurter/BCE
    | usano EUR come base, quindi il pivot di default è EUR.
    */
    'pivot_currency' => env('FINANCE_PIVOT_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Provider tassi di cambio
    |--------------------------------------------------------------------------
    | Frankfurter (https://frankfurter.dev) espone i tassi di riferimento BCE
    | senza API key. `history_start` è la data minima per il backfill storico.
    */
    'rates' => [
        'provider_url' => env('FINANCE_RATES_URL', 'https://api.frankfurter.app'),
        'history_start' => env('FINANCE_RATES_HISTORY_START', '2015-01-01'),
        'timeout' => (int) env('FINANCE_RATES_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Valute supportate
    |--------------------------------------------------------------------------
    | Sottoinsieme ISO 4217 coperto dai tassi BCE/Frankfurter. Usata per
    | popolare i select nel frontend e validare gli input.
    */
    'currencies' => [
        'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'NZD', 'CNY', 'HKD',
        'SGD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'ISK',
        'TRY', 'ILS', 'INR', 'KRW', 'THB', 'IDR', 'MYR', 'PHP', 'ZAR', 'MXN', 'BRL',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifiche
    |--------------------------------------------------------------------------
    | Le notifiche in-app (canale database) sono sempre attive. Il canale mail
    | è gate-ato qui: in dev MAIL_MAILER=log scrive su storage/logs, in prod
    | va configurato lo SMTP.
    */
    'notifications' => [
        'mail' => (bool) env('FINANCE_NOTIFY_MAIL', true),
    ],
];
