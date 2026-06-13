<?php

namespace App\Notifications\Contracts;

interface Dedupable
{
    /**
     * Chiave univoca per evitare notifiche duplicate nello stesso periodo.
     */
    public function dedupKey(): string;
}
