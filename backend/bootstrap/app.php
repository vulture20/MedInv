<?php

use App\Http\Middleware\EnsureUserHasLevel;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\LogFrontendAccess;
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
        $middleware->statefulApi();

        // Every request the SPA makes against the API — see LogFrontendAccess's
        // docblock for why this, not the nginx-served static files, is what
        // "frontend access" means from Laravel's side of the stack.
        $middleware->api(append: [LogFrontendAccess::class]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'level' => EnsureUserHasLevel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
