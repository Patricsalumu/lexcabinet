<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Multi-cabinet : chaque requête web est placée dans le cabinet de l'utilisateur connecté.
        $middleware->web(append: [\App\Http\Middleware\SetCurrentFirm::class]);
        // … avant la liaison des modèles de route : {case}, {invoice}… sont cherchés dans ce cabinet.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\SetCurrentFirm::class,
        );

        $middleware->alias([
            'account.usable' => \App\Http\Middleware\EnsureAccountIsUsable::class,
            'two-factor' => \App\Http\Middleware\EnsureTwoFactorIsConfigured::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
