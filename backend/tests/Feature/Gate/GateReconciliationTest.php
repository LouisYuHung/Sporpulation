<?php

namespace Tests\Feature\Gate;

use App\Gate\SeatGate;
use App\Metrics\MetricRegistry;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class GateReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection('gate')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
        }
    }

    private function gate(): SeatGate
    {
        return app(SeatGate::class);
    }

    private function key(Activity $activity): string
    {
        return 'gate:activity:'.$activity->id;
    }

    /**
     * 這是對帳存在的理由。閘門比實際嚴格時，使用者會收到「額滿」而資料庫裡明明還有
     * 位子 —— 沒有錯誤、沒有例外、沒有任何訊號，只有使用者在客服信箱裡抱怨。
     */
    #[Test]
    public function it_reopens_a_gate_that_had_drifted_shut(): void
    {
        $activity = Activity::factory()->withCapacity(5)->create();

        // 模擬「扣了 token 卻沒佔到名額」累積出來的漂移。
        Redis::connection('gate')->set($this->key($activity), 0);

        $this->assertSame(5, $this->gate()->reconcile($activity));
        $this->assertSame(5, $this->gate()->remaining($activity));
    }

    #[Test]
    public function it_closes_a_gate_that_had_drifted_open(): void
    {
        $activity = Activity::factory()->withCapacity(5)->create(['joined_count' => 4]);

        Redis::connection('gate')->set($this->key($activity), 5);

        $this->assertSame(-4, $this->gate()->reconcile($activity));
        $this->assertSame(1, $this->gate()->remaining($activity));
    }

    #[Test]
    public function it_reports_no_drift_when_the_gate_is_already_right(): void
    {
        $activity = Activity::factory()->withCapacity(3)->create(['joined_count' => 1]);

        Redis::connection('gate')->set($this->key($activity), 2);

        $this->assertSame(0, $this->gate()->reconcile($activity));
        $this->assertSame(2, $this->gate()->remaining($activity));
    }

    /**
     * 進行中的預扣，在對帳眼中和「偏嚴格的漂移」長得一模一樣：token 已經扣了，
     * 但資料庫還沒動。對帳會把它還回去。
     *
     * 這是「先讀資料庫、再覆蓋 Redis」那個空隙的具體樣子，而它往安全的方向偏 ——
     * 閘門變得比實際寬鬆，多放幾個請求進 MySQL 被條件式 UPDATE 擋下。反過來
     * （對帳讓閘門變得比真相嚴格）不可能發生，因為寫進去的值就是資料庫此刻的
     * 空位數。所以這裡不需要 CAS，也不需要版本號。
     *
     * 代價是對帳跑在搶購正中間時會抹掉當下所有的預扣，讓那一瞬間多放一批人進來。
     * 五分鐘一次的間隔就是在賭「不會剛好落在那幾秒」。
     */
    #[Test]
    public function an_in_flight_reservation_looks_like_drift_and_is_returned(): void
    {
        $activity = Activity::factory()->withCapacity(3)->create();

        $this->gate()->admit($activity);
        $this->assertSame(2, $this->gate()->remaining($activity));

        $this->assertSame(1, $this->gate()->reconcile($activity), '預扣被當成偏嚴格的漂移');
        $this->assertSame(3, $this->gate()->remaining($activity));
    }

    /**
     * 對帳只校正已經存在的閘門，不建立新的。建立是「第一個請求抵達」的事 ——
     * 對帳如果會建，每一個沒有流量的活動都會佔著記憶體。
     */
    #[Test]
    public function it_does_not_create_a_gate_for_an_activity_that_has_none(): void
    {
        $activity = Activity::factory()->withCapacity(5)->create();

        $this->assertSame(0, $this->gate()->reconcile($activity));
        $this->assertNull($this->gate()->remaining($activity));
    }

    /**
     * KEEPTTL：閘門的壽命從建立那一刻算起。對帳替它續命的話，熱門活動的閘門永遠
     * 不會過期重建 —— 而過期重建本身就是一條自動回頭看真相的路徑。
     */
    #[Test]
    public function reconciling_does_not_extend_the_gates_life(): void
    {
        $activity = Activity::factory()->withCapacity(5)->create();
        $redis = Redis::connection('gate');

        $redis->set($this->key($activity), 0, 'EX', 40);

        $this->gate()->reconcile($activity);

        $this->assertLessThanOrEqual(40, $redis->ttl($this->key($activity)));
        $this->assertGreaterThan(0, $redis->ttl($this->key($activity)));
    }

    /**
     * 指令要真的找得到閘門。
     *
     * SCAN 的 cursor 必須從 null 開始 —— phpredis 把 0 當成「迭代已結束」，於是第一次
     * 呼叫就回傳 false。那個錯誤不會有紅字，只會讓這支指令每次都說「檢查了 0 個閘門，
     * 一切正常」。這個測試存在就是為了讓那種沉默變成紅字。
     */
    #[Test]
    public function the_command_finds_live_gates_and_fixes_them(): void
    {
        $drifted = Activity::factory()->withCapacity(4)->create();
        $healthy = Activity::factory()->withCapacity(4)->create();

        Redis::connection('gate')->set($this->key($drifted), 0);
        Redis::connection('gate')->set($this->key($healthy), 4);

        $this->artisan('gate:reconcile')
            ->expectsOutputToContain('檢查 2 個閘門，1 個有漂移')
            ->assertSuccessful();

        $this->assertSame(4, $this->gate()->remaining($drifted));
        $this->assertSame(4, $this->gate()->remaining($healthy));
    }

    /**
     * 漂移的兩個方向後果不對稱，指標必須分開記 —— too_strict 是要告警的那個，
     * too_generous 只需要知道。合在一起就沒有辦法對前者設門檻。
     */
    #[Test]
    public function it_records_the_two_drift_directions_separately(): void
    {
        $tooStrict = Activity::factory()->withCapacity(6)->create();
        $tooGenerous = Activity::factory()->withCapacity(6)->create(['joined_count' => 5]);

        Redis::connection('gate')->set($this->key($tooStrict), 4);      // 真相是 6，少 2
        Redis::connection('gate')->set($this->key($tooGenerous), 6);    // 真相是 1，多 5

        $this->artisan('gate:reconcile')->assertSuccessful();

        $rendered = app(MetricRegistry::class)->render();

        $this->assertStringContainsString('sporpulation_gate_drift_total{direction="too_strict"} 2', $rendered);
        $this->assertStringContainsString('sporpulation_gate_drift_total{direction="too_generous"} 5', $rendered);
        $this->assertStringContainsString('sporpulation_gate_reconciliations_total{result="drifted"} 2', $rendered);
    }

    /**
     * 端到端：漂移關上的閘門在誤殺使用者，對帳跑完之後同一個人就報得進去了。
     */
    #[Test]
    public function a_user_shut_out_by_drift_can_register_after_reconciliation(): void
    {
        $activity = Activity::factory()->withCapacity(3)->create();
        $user = User::factory()->create();

        Redis::connection('gate')->set($this->key($activity), 0);

        $this->actingAs($user)
            ->postJson("/api/activities/{$activity->id}/registration")
            ->assertStatus(409);

        $this->artisan('gate:reconcile')->assertSuccessful();

        $this->actingAs($user)
            ->postJson("/api/activities/{$activity->id}/registration")
            ->assertCreated();
    }
}
