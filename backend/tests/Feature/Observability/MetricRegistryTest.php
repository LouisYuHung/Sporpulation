<?php

namespace Tests\Feature\Observability;

use App\Metrics\MetricRegistry;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

class MetricRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection('metrics')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
        }
    }

    /**
     * 測試不吃 config/metrics.php 的內容 —— 那份定義會隨產品演進而改，測試如果跟著
     * 它走，改一個標籤名就會弄紅一批和它無關的測試。
     */
    private function registry(): MetricRegistry
    {
        return new MetricRegistry(
            app(RedisFactory::class),
            definitions: [
                'things_total' => [
                    'type' => 'counter',
                    'help' => '測試用的計數器。',
                    'labels' => ['outcome'],
                ],
                'work_duration_seconds' => [
                    'type' => 'histogram',
                    'help' => '測試用的直方圖。',
                    'labels' => [],
                    'buckets' => [0.1, 1.0],
                ],
            ],
            namespace: 'testapp',
        );
    }

    public function test_it_accumulates_a_counter_across_calls(): void
    {
        $registry = $this->registry();

        $registry->increment('things_total', ['outcome' => 'granted']);
        $registry->increment('things_total', ['outcome' => 'granted']);
        $registry->increment('things_total', ['outcome' => 'rejected'], 3);

        $output = $registry->render();

        $this->assertStringContainsString('# TYPE testapp_things_total counter', $output);
        $this->assertStringContainsString('testapp_things_total{outcome="granted"} 2', $output);
        $this->assertStringContainsString('testapp_things_total{outcome="rejected"} 3', $output);
    }

    /**
     * 計數器累加是跨行程的，這才是它存在 Redis 的理由。用兩個各自獨立的 registry
     * 實例來代表兩個節點 —— 換成行程內的靜態變數，這個測試會得到 1 而不是 2。
     */
    public function test_two_instances_share_the_same_counter(): void
    {
        $this->registry()->increment('things_total', ['outcome' => 'granted']);
        $this->registry()->increment('things_total', ['outcome' => 'granted']);

        $this->assertStringContainsString(
            'testapp_things_total{outcome="granted"} 2',
            $this->registry()->render(),
        );
    }

    /**
     * 直方圖的桶必須是累積的：le="1" 那一格要包含所有 ≤ 1 的觀測，不只是落在
     * (0.1, 1] 之間的那些。存的時候是分開存的，累加發生在輸出。
     */
    public function test_histogram_buckets_are_cumulative(): void
    {
        $registry = $this->registry();

        $registry->observe('work_duration_seconds', [], 0.05);   // -> le 0.1
        $registry->observe('work_duration_seconds', [], 0.5);    // -> le 1
        $registry->observe('work_duration_seconds', [], 9.0);    // -> +Inf

        $output = $registry->render();

        $this->assertStringContainsString('testapp_work_duration_seconds_bucket{le="0.1"} 1', $output);
        $this->assertStringContainsString('testapp_work_duration_seconds_bucket{le="1"} 2', $output);
        $this->assertStringContainsString('testapp_work_duration_seconds_bucket{le="+Inf"} 3', $output);
        $this->assertStringContainsString('testapp_work_duration_seconds_sum 9.55', $output);
        $this->assertStringContainsString('testapp_work_duration_seconds_count 3', $output);
    }

    /**
     * 邊界值屬於它自己那一格：le 的語意是「小於等於」。
     */
    public function test_a_value_exactly_on_the_boundary_falls_in_that_bucket(): void
    {
        $registry = $this->registry();
        $registry->observe('work_duration_seconds', [], 0.1);

        $this->assertStringContainsString(
            'testapp_work_duration_seconds_bucket{le="0.1"} 1',
            $registry->render(),
        );
    }

    public function test_it_rejects_a_metric_that_was_never_declared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->increment('typo_total', ['outcome' => 'granted']);
    }

    /**
     * 標籤集不吻合宣告時要炸掉，不能安靜地開一條新的時間序列。這是 Prometheus
     * 最常見的踩雷方式：圖表上看到的是「數字掉了一半」，原因卻是打錯一個字。
     */
    public function test_it_rejects_a_label_set_that_does_not_match_the_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->increment('things_total', ['outcomes' => 'granted']);
    }

    public function test_it_rejects_using_a_counter_as_a_histogram(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->observe('things_total', ['outcome' => 'granted'], 0.5);
    }

    /**
     * 沒有資料時不要輸出只有 HELP/TYPE 的空指標 —— 那會讓 Grafana 畫出一條
     * 存在但永遠沒有值的序列。
     */
    public function test_it_emits_nothing_for_a_metric_with_no_data(): void
    {
        $this->assertSame('', $this->registry()->render());
    }

    public function test_a_gauge_is_evaluated_at_scrape_time(): void
    {
        $current = 5;
        $registry = $this->registry();

        $registry->gauge('backlog', '待處理的工作數。', function () use (&$current) {
            return $current;
        });

        $this->assertStringContainsString('testapp_backlog 5', $registry->render());

        $current = 2;

        $this->assertStringContainsString('testapp_backlog 2', $registry->render());
    }
}
