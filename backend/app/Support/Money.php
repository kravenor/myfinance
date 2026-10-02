<?php

namespace App\Support;

use NumberFormatter;

/** Importi nei testi delle notifiche (in-app, email, push): formato italiano, come nel frontend. */
class Money
{
    public static function format(float|string $amount, string $currency): string
    {
        $formatter = new NumberFormatter('it_IT', NumberFormatter::CURRENCY);
        $text = $formatter->formatCurrency((float) $amount, strtoupper($currency));

        return $text === false ? number_format((float) $amount, 2, ',', '.').' '.$currency : $text;
    }
}
