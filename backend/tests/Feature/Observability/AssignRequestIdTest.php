<?php

namespace Tests\Feature\Observability;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignRequestIdTest extends TestCase
{
    public function test_it_assigns_a_request_id_when_none_is_supplied(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertOk();
        $response->assertHeader(AssignRequestId::HEADER);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $response->headers->get(AssignRequestId::HEADER),
        );
    }

    public function test_it_keeps_the_id_supplied_by_the_caller(): void
    {
        $response = $this->withHeaders([AssignRequestId::HEADER => 'edge-abc123'])
            ->getJson('/api/ping');

        $response->assertHeader(AssignRequestId::HEADER, 'edge-abc123');
    }

    /**
     * 不可信輸入的部分。被拒絕的值必須「完全不出現」在回應裡 —— 只要原樣回送，
     * 這個標頭就成了把任意字元灌進 log 收集端的管道。
     *
     * @return array<string, array{string}>
     */
    public static function rejectedIds(): array
    {
        return [
            'too long' => [str_repeat('a', 65)],
            'newline injects a fake log line' => ["abc\n{\"level\":\"info\"}"],
            // PCRE 的 $ 預設也匹配「結尾換行之前」，所以換行在最後的這一個
            // 會穿過沒有 D 修飾子的樣式 —— 而那正是 log injection 最常見的形狀。
            'trailing newline' => ["abc\n"],
            'spaces' => ['abc def'],
            'quotes break the json' => ['abc"def'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedIds')]
    public function test_it_replaces_an_id_that_is_not_shaped_like_an_id(string $supplied): void
    {
        $response = $this->withHeaders([AssignRequestId::HEADER => $supplied])
            ->getJson('/api/ping');

        $assigned = $response->headers->get(AssignRequestId::HEADER);

        $this->assertNotSame($supplied, $assigned);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $assigned);
    }

    /**
     * 回應標頭只證明「middleware 跑過了」。真正的目的是讓之後每一筆 log 都自動
     * 帶上 id，所以要直接檢查 shared context。
     */
    public function test_it_shares_the_id_and_the_node_with_every_log_line(): void
    {
        $response = $this->getJson('/api/ping');

        $context = Log::sharedContext();

        $this->assertSame(
            $response->headers->get(AssignRequestId::HEADER),
            $context['request_id'] ?? null,
        );
        $this->assertSame(gethostname(), $context['node'] ?? null);
    }

    /**
     * 註冊為全域而不是掛在 api 群組上的理由：未匹配的路由根本不會進入群組，
     * 而 404 正好是最需要能追查「到底打了什麼」的那種請求。
     */
    public function test_it_runs_for_routes_that_do_not_exist(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist');

        $response->assertNotFound();
        $response->assertHeader(AssignRequestId::HEADER);
    }
}
