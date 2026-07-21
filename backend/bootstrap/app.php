<?php

use App\Exceptions\ResourceNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);

        // Global rather than appended to the api group: route middleware such
        // as auth:sanctum has middleware priority and would otherwise run
        // first, and unmatched routes never enter the group at all - both
        // cases would then render their errors in the wrong locale.
        $middleware->prepend(\App\Http\Middleware\SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );

        // Framework exceptions carry hardcoded English messages, so they are
        // re-rendered from lang/{locale}/messages.php. The locale is already
        // resolved by SetLocale, which runs before any route middleware.

        $exceptions->render(fn (AuthenticationException $e) => response()->json([
            'message' => __('messages.errors.unauthenticated'),
        ], 401));

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e) => response()->json([
            'message' => __('messages.errors.forbidden'),
        ], 403));

        // Ours: the message is already localised. Registered before the generic
        // handler below, which would otherwise swallow it.
        $exceptions->render(fn (ResourceNotFoundException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 404));

        // Framework 404s (unmatched route, failed route model binding) carry
        // internal English text such as "No query results for model [...]",
        // which must never reach the client.
        $exceptions->render(fn (NotFoundHttpException $e) => response()->json([
            'message' => __('messages.errors.not_found'),
        ], 404));
    })->create();
