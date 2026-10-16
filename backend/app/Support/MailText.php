<?php

namespace App\Support;

/**
 * Testo salvato (descrizioni importate, nomi, indirizzi) da mettere in una riga markdown
 * dell'email. Markdown::withSecuredEncoding fa l'escape di [ e < ma non di \:
 * '\[testo](url)' diventerebbe '\\[testo](url)', cioè un backslash seguito da un link vero.
 */
class MailText
{
    public static function escape(string $text): string
    {
        return str_replace('\\', '\\\\', $text);
    }
}
