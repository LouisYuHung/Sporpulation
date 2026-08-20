<?php

use App\Exceptions\ConflictException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\SetLocale;
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

        // 註冊為全域而非附加在 api 群組上：像 auth:sanctum 這類路由 middleware
        // 具有較高的執行優先序，否則會先跑；而未匹配的路由更是根本不會進入群組 -
        // 這兩種情況都會導致錯誤訊息用錯語系呈現。
        $middleware->prepend(SetLocale::class);

        // 以路由為單位選擇啟用而非全域套用：只有值得保護的寫入才需要付出額外的
        // 往返成本。
        $middleware->alias([
            'idempotent' => EnsureIdempotentRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );

        // 框架的例外帶的是寫死的英文訊息，因此改由 lang/{locale}/messages.php
        // 重新輸出。語系在此之前已由 SetLocale 解析完成，它會在所有路由
        // middleware 之前執行。

        $exceptions->render(fn (AuthenticationException $e) => response()->json([
            'message' => __('messages.errors.unauthenticated'),
        ], 401));

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e) => response()->json([
            'message' => __('messages.errors.forbidden'),
        ], 403));

        // 我們自己的例外：訊息已經在地化。註冊在下方的通用處理器之前，否則會被
        // 那個處理器吃掉。
        $exceptions->render(fn (ResourceNotFoundException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 404));

        // 在競爭中落敗（活動額滿、報名已截止）。code 不隨語系改變，讓用戶端可以
        // 依結果分支處理。
        $exceptions->render(fn (ConflictException $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode(),
        ], 409));

        // 被限流擋下。getHeaders() 帶著 Retry-After - 少了它，呼叫端只知道「不行」，
        // 不知道什麼時候可以再試，於是只能盲目重試，反而把限流當成節拍器在打。
        $exceptions->render(fn (TooManyRequestsException $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode(),
        ], 429, $e->getHeaders()));

        // 框架自己的 404（路由未匹配、route model binding 失敗）帶有像
        // 「No query results for model [...]」這類內部英文文字，絕不能傳到
        // 用戶端。
        $exceptions->render(fn (NotFoundHttpException $e) => response()->json([
            'message' => __('messages.errors.not_found'),
        ], 404));
    })->create();
