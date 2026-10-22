<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ResolveActiveBusiness;
use App\Http\Middleware\ProductionSecurity;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Http\Middleware\RequirePrivilegedMfa;
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
        $middleware->alias([
            'business' => ResolveActiveBusiness::class,
            'platform' => EnsurePlatformAdmin::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/workspace');
        $middleware->web(
            prepend: [TrustConfiguredProxies::class],
            append: [ProductionSecurity::class, RequirePrivilegedMfa::class],
        );
        // These endpoints authenticate requests with per-tenant provisioned
        // HMAC signing or a bearer device credential, not browser cookies.
        $middleware->validateCsrfTokens(except:[
            'integrations/webhook/*','print-agent/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();