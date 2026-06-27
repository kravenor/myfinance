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
    | Provider quotazioni strumenti (auto-fetch prezzi)
    |--------------------------------------------------------------------------
    | Mappa asset_type → provider. Yahoo Finance (endpoint chart pubblico, no
    | API key) copre stock/etf/fund incluse le borse EU (symbol Yahoo, es.
    | CSSPX.MI, SXR8.DE) e restituisce la valuta; CoinGecko le crypto (symbol =
    | id CoinGecko, es. "bitcoin"). Sono API non ufficiali/free, per uso
    | personale/non commerciale: rivedere in scenario multi-tenant (ADR 0001).
    */
    'prices' => [
        'providers' => [
            'stock' => 'yahoo',
            'etf' => 'yahoo',
            'fund' => 'yahoo',
            'crypto' => 'coingecko',
        ],
        'yahoo' => [
            'url' => env('FINANCE_YAHOO_URL', 'https://query1.finance.yahoo.com'),
            'timeout' => (int) env('FINANCE_PRICES_TIMEOUT', 15),
        ],
        'coingecko' => [
            'url' => env('FINANCE_COINGECKO_URL', 'https://api.coingecko.com/api/v3'),
            'api_key' => env('FINANCE_COINGECKO_API_KEY'), // opzionale (demo/pro)
            'timeout' => (int) env('FINANCE_PRICES_TIMEOUT', 15),
            'vs_currency' => env('FINANCE_COINGECKO_VS', 'EUR'),
        ],
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
