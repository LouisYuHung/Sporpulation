<?php

namespace Tests\Feature\Idempotency;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 路由上的後端名稱打錯字，原本要等到第一個帶 Idempotency-Key 的請求進來才會炸 -
 * middleware 是在 early return 之後才解析後端的。這個測試把訊號提前到部署之前。
 */
class RouteStoreNamesTest extends TestCase
{
    #[Test]
    public function every_route_names_a_configured_store(): void
    {
        $configured = array_keys(config('idempotency.stores'));
        $router = app('router');
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                // 別名會被解析成「類別:參數」，例如
                // App\Http\Middleware\EnsureIdempotentRequest:redis
                if (! is_string($middleware) || ! str_starts_with($middleware, EnsureIdempotentRequest::class.':')) {
                    continue;
                }

                $name = explode(':', $middleware, 2)[1];
                $checked++;

                $this->assertContains(
                    $name,
                    $configured,
                    "路由 [{$route->uri()}] 指定了不存在的冪等後端 [{$name}]",
                );
            }
        }

        // 這個測試自己也需要保護：middleware 改名、或路由全部改成不帶參數時，上面
        // 的迴圈會一次都不執行 —— 而一個什麼都沒檢查的迴圈測試會安靜地通過。
        $this->assertGreaterThan(0, $checked, '沒有掃到任何帶參數的冪等路由，這個測試可能已經失效');
    }

    #[Test]
    public function the_default_store_is_configured(): void
    {
        // 沒指定後端的路由會落到這個值上，它同樣可能被打錯。
        $this->assertContains(
            config('idempotency.default'),
            array_keys(config('idempotency.stores')),
        );
    }
}
