<?php

use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\isOwner;
use App\Http\Middleware\isPublicRoute;
use App\Http\Middleware\isTenant;
use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        
        $middleware->alias([
            'is_admin' => IsAdmin::class,
            'is_owner' => isOwner::class,
            'is_tenant' => isTenant::class,
            'is_public' => isPublicRoute::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
