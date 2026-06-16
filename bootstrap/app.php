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
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel 11 по умолчанию делает redirectGuestsTo(fn() => route('login')),
        // но у нас login — это Vue-страница, а Laravel-роута 'login' нет.
        // Для API возвращаем null → exception handler ниже выдаст 401 JSON;
        // для web возвращаем строковый '/login' — Vue SPA сам отрисует логин.
        $middleware->redirectGuestsTo(fn (Request $request) =>
            $request->expectsJson() || $request->is('api/*') ? null : '/login'
        );
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'restrict.education' => \App\Http\Middleware\RestrictEducationWrites::class,
            'restrict.head' => \App\Http\Middleware\RestrictHeadWrites::class,
            'restrict.support' => \App\Http\Middleware\RestrictSupportWrites::class,
            'restrict.corrections' => \App\Http\Middleware\RestrictCorrectionsWrites::class,
            'maintenance' => \App\Http\Middleware\EnsureNotInMaintenance::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            return redirect('/login');
        });
    })->create();
