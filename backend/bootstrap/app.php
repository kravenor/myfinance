<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->throttleApi();

        // Dietro il reverse proxy che termina TLS (Apache sul VPS, Traefik sulla
        // Pi) l'app deve fidarsi di X-Forwarded-*: senza, Laravel vede `http` e
        // genera redirect e link (reset password) in chiaro. Ristretto alle reti
        // private: i container non sono raggiungibili direttamente da internet,
        // quindi solo il proxy può presentare quegli header. Host escluso: Apache
        // (ProxyPreserveHost) e Traefik passano già quello originale.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
        // Solo l'host di APP_URL; loopback per l'health check del deploy (wget su 127.0.0.1).
        $middleware->trustHosts(at: ['^127\.0\.0\.1$']);
        $middleware->validateCsrfTokens(except: [
            'sanctum/csrf-cookie',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
