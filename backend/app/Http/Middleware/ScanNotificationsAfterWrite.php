<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\NotificationScanner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dopo una scrittura riuscita su dati che gli avvisi leggono (transazioni, budget, obiettivi,
 * preferenze) ricontrolla gli avvisi dell'utente. terminate() gira dopo l'invio della risposta:
 * l'utente non aspetta, e un import di centinaia di righe fa una sola scansione.
 * La dedup per stato/periodo evita doppioni con la scansione delle 07:00.
 */
class ScanNotificationsAfterWrite
{
    public function __construct(private readonly NotificationScanner $scanner) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if ($request->isMethodSafe() || ! $response->isSuccessful() || ! $user instanceof User) {
            return;
        }

        $this->scanner->scan($user);
    }
}
