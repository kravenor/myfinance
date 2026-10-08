<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** Righe per pagina dalla query string, tra 1 e 200 (il massimo che chiede il frontend per le select). */
    protected function perPage(Request $request, int $default): int
    {
        return min(max($request->integer('per_page', $default), 1), 200);
    }
}
