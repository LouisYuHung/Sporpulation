<?php

namespace Tests\Feature\Gate;

use App\Gate\GateDecision;
use App\Gate\SeatGate;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class SeatGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 閘門是 fail-open 的：Redis 不可達時 admit() 回傳 Unknown、一律放行。
        // 少了這個 skip，沒有 Redis 的環境會「通過但什麼都沒測到」。
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

    #[Test]
    public function it_builds_itself_from_the_database_on_first_use(): void
    {
        $activity = Activity::factory()->withCapacity(3)->create();

        $this->assertNull($this->gate()->remaining($activity), '一開始不該有閘門');

        $this->assertSame(GateDecision::Admitted, $this->gate()->admit($activity));
        $this->assertSame(2, $this->gate()->remaining($activity));
    }

    /**
     * 已經有人報名時，閘門建起來的初值必須扣掉那些人 —— 直接用 capacity 當初值
     * 會讓每次重建都憑空多出一批名額。
     */
    #[Test]
    public function it_starts_from_the_seats_that_are_actually_free(): void
    {
        $activity = Activity::factory()->withCapacity(5)->create(['joined_count' => 4]);

        $this->assertSame(GateDecision::Admitted, $this->gate()->admit($activity));
        $this->assertSame(0, $this->gate()->remaining($activity));
        $this->assertSame(GateDecision::Shed, $this->gate()->admit($activity));
    }

    /**
     * 削峰的定義：被擋下的請求不會產生任何一句 SQL。這是整個 M6 的目的，
     * 也是唯一能證明它真的有用的斷言。
     */
    #[Test]
    public function a_shed_request_never_touches_the_database(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();

        $this->gate()->admit($activity);      // 用掉唯一的名額（這一次會讀資料庫來建閘門）

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->assertSame(GateDecision::Shed, $this->gate()->admit($activity));

        $this->assertSame([], $queries, '被閘門擋下的請求不該產生任何 SQL');
    }

    /**
     * 少還一次 token，閘門就永遠比實際嚴格一格 —— 而那一格會一直誤殺使用者，
     * 直到閘門過期為止，中間不會有任何錯誤訊息。
     */
    #[Test]
    public function a_released_token_can_be_taken_again(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();

        $this->assertSame(GateDecision::Admitted, $this->gate()->admit($activity));
        $this->assertSame(GateDecision::Shed, $this->gate()->admit($activity));

        $this->gate()->release($activity);

        $this->assertSame(1, $this->gate()->remaining($activity));
        $this->assertSame(GateDecision::Admitted, $this->gate()->admit($activity));
    }

    /**
     * Activity::releaseSeat() 的 `where joined_count > 0` 的鏡像：同一種安全網，
     * 同一個理由 —— 只要每次歸還都與一次取用配對，它就不會被觸發。
     */
    #[Test]
    public function it_refuses_to_return_more_tokens_than_the_capacity(): void
    {
        $activity = Activity::factory()->withCapacity(2)->create();

        $this->gate()->admit($activity);
        $this->gate()->release($activity);

        $this->assertSame(2, $this->gate()->remaining($activity));

        $this->gate()->release($activity);   // 多還的那一次

        $this->assertSame(2, $this->gate()->remaining($activity), '閘門不該超過容量');
    }

    /**
     * 閘門過期之後歸還，不能憑空造出一個 key —— 那個 key 沒有 TTL，而且它的值
     * 與真實名額毫無關係。那比沒有閘門更糟：沒有閘門會 fail-open 放行，
     * 一個錯的閘門會擋人。
     */
    #[Test]
    public function releasing_into_an_expired_gate_does_not_recreate_it(): void
    {
        $activity = Activity::factory()->withCapacity(2)->create();

        $this->gate()->admit($activity);
        Redis::connection('gate')->del('gate:activity:'.$activity->id);

        $this->gate()->release($activity);

        $this->assertNull($this->gate()->remaining($activity));
    }

    /**
     * DECR 不會動到 TTL。這和 M1 的「HSET 保留既有 TTL」是同一件事：閘門的壽命
     * 應該從它建立的那一刻算起，不該被流量續命 —— 否則熱門活動的閘門永遠不會
     * 重建，也就永遠不會回頭跟資料庫對一次帳。
     */
    #[Test]
    public function taking_a_token_does_not_extend_the_gates_life(): void
    {
        config(['gate.ttl' => 100]);

        $activity = Activity::factory()->withCapacity(5)->create();
        $key = 'gate:activity:'.$activity->id;

        $this->gate()->admit($activity);
        Redis::connection('gate')->expire($key, 30);

        $this->gate()->admit($activity);
        $this->gate()->release($activity);

        $this->assertLessThanOrEqual(30, Redis::connection('gate')->ttl($key));
        $this->assertGreaterThan(0, Redis::connection('gate')->ttl($key));
    }

    /**
     * 關掉閘門時必須是 Unknown（放行、沒有 token），不能是 Shed，也不能是
     * Admitted —— 後者會讓呼叫端以為自己欠了一個 token，於是去歸還一個不存在
     * 的東西。
     */
    #[Test]
    public function a_disabled_gate_admits_without_taking_a_token(): void
    {
        config(['gate.enabled' => false]);

        $activity = Activity::factory()->withCapacity(1)->create();

        $decision = $this->gate()->admit($activity);

        $this->assertSame(GateDecision::Unknown, $decision);
        $this->assertFalse($decision->consumedToken());
        $this->assertNull($this->gate()->remaining($activity));
    }

    /**
     * Redis 不可達時要放行，不是擋下。閘門是最佳化，不是正確性的來源 ——
     * 它自己故障時該讓路，讓 MySQL 照常裁決。
     */
    #[Test]
    public function an_unreachable_redis_admits_instead_of_shedding(): void
    {
        // RedisManager 在建構時就把 database.redis 整份抄一份下來，所以光改 config
        // 沒有用 —— 必須讓容器忘掉那個 singleton，下次解析才會讀到新的設定。
        config(['database.redis.gate.host' => '127.0.0.1', 'database.redis.gate.port' => 1]);
        $this->app->forgetInstance('redis');

        $activity = Activity::factory()->withCapacity(1)->create();

        $this->assertSame(GateDecision::Unknown, $this->gate()->admit($activity));
    }

    #[Test]
    public function only_an_admitted_decision_owes_a_token(): void
    {
        $this->assertTrue(GateDecision::Admitted->consumedToken());
        $this->assertFalse(GateDecision::Shed->consumedToken());
        $this->assertFalse(GateDecision::Unknown->consumedToken());
    }
}
