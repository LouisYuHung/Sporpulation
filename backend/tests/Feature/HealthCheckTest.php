<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 取代 Laravel 骨架附的 ExampleTest：那支測試打的是 `/`，而這是純 API 專案，
 * 根路徑本來就沒有路由，因此它從第一天起就是紅的。
 */
class HealthCheckTest extends TestCase
{
    #[Test]
    public function the_api_answers_a_ping(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJsonPath('message', 'pong')
            ->assertJsonStructure(['message', 'timestamp']);
    }

    #[Test]
    public function the_health_endpoint_is_up(): void
    {
        // bootstrap/app.php 用 health: '/up' 註冊，容器與部署的健康檢查會打這裡。
        $this->get('/up')->assertOk();
    }

    #[Test]
    public function the_root_path_has_no_route_and_says_so_in_json(): void
    {
        // 沒有前端由 Laravel 提供，因此 `/` 是 404 —— 而且要是乾淨的 JSON，
        // 不能漏出框架的英文內部訊息。
        $this->getJson('/')
            ->assertNotFound()
            ->assertJsonPath('message', __('messages.errors.not_found'));
    }
}
