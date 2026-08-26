<?php

namespace Tests\Feature\Gate;

use App\Gate\SeatGate;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * 閘門接上報名路徑之後的行為。SeatGateTest 測的是閘門這個元件本身；這裡測的是
 * 「它有沒有真的接在該接的地方」。
 */
class GateAdmissionTest extends TestCase
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

    /**
     * 削峰的定義：被閘門擋下的請求不會產生任何一句寫入名額的 SQL。
     *
     * 斷言刻意不是「零 SQL」—— auth:sanctum 與 route model binding 都排在
     * controller 之前，每個請求本來就會付出幾次 SELECT。這跟 M1 量限流時
     * 「降到 37%，但降不到零」是同一件事，理由也一樣。
     */
    #[Test]
    public function a_shed_request_never_writes_to_the_database(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();

        $this->join($activity, User::factory()->create())->assertCreated();

        // 參賽者要在開始監聽之前造好，否則 factory 自己的 insert 會被算進來 ——
        // 那會讓這個測試以為請求寫了資料庫。
        $latecomer = User::factory()->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->join($activity, $latecomer)->assertStatus(409);

        $writes = array_values(array_filter(
            $queries,
            fn (string $sql) => str_starts_with($sql, 'update') || str_starts_with($sql, 'insert'),
        ));

        $this->assertSame([], $writes, '被閘門削掉的請求不該寫入任何東西');
    }

    /**
     * 同樣的情境，關掉閘門當對照組：證明上面那個「沒有寫入」是閘門造成的，
     * 不是這個情境本來就不會寫。
     */
    #[Test]
    public function without_the_gate_the_same_request_reaches_the_database(): void
    {
        config(['gate.enabled' => false]);

        $activity = Activity::factory()->withCapacity(1)->create();

        $this->join($activity, User::factory()->create())->assertCreated();

        // 參賽者要在開始監聽之前造好，否則 factory 自己的 insert 會被算進來 ——
        // 那會讓這個測試以為請求寫了資料庫。
        $latecomer = User::factory()->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->join($activity, $latecomer)->assertStatus(409);

        $writes = array_values(array_filter(
            $queries,
            fn (string $sql) => str_starts_with($sql, 'update') || str_starts_with($sql, 'insert'),
        ));

        $this->assertNotSame([], $writes, '沒有閘門時，搶輸的請求會一路打到資料庫');
    }

    /**
     * 放行之後卻沒佔到名額，token 必須還回去。
     *
     * 用「活動已開始」這條路徑：閘門看得到還有空位所以放行，但 join() 會丟
     * ActivityClosedException。少了 controller 裡那個 catch 的歸還，閘門就永遠
     * 少一格 —— 而那一格會持續誤殺，直到閘門過期為止，中間沒有任何錯誤訊息。
     */
    #[Test]
    public function a_token_taken_for_an_attempt_that_claimed_nothing_is_returned(): void
    {
        $activity = Activity::factory()->started()->withCapacity(3)->create();
        $gate = app(SeatGate::class);

        $this->join($activity, User::factory()->create())->assertStatus(409);

        $this->assertSame(3, $gate->remaining($activity), 'token 沒有被歸還');
    }

    /**
     * 取消釋出的名額要回到閘門。這條路徑走的是 Activity::releaseSeat()，也就是
     * joined_count 真正減少的那一行 —— 放在那裡，任何呼叫端（controller、壓測
     * 指令、console）釋出名額時閘門都會知道。
     */
    #[Test]
    public function cancelling_gives_the_seat_back_to_the_gate(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();
        $user = User::factory()->create();
        $gate = app(SeatGate::class);

        $this->join($activity, $user)->assertCreated();
        $this->assertSame(0, $gate->remaining($activity));

        // 額滿，下一個人被削掉。
        $this->join($activity, User::factory()->create())->assertStatus(409);

        $this->actingAs($user)->deleteJson("/api/activities/{$activity->id}/registration")
            ->assertOk();

        $this->assertSame(1, $gate->remaining($activity), '取消之後閘門必須放人進來');
        $this->join($activity, User::factory()->create())->assertCreated();
    }

    /**
     * 模型層的取消也要通知閘門 —— 壓測指令與 console 都是這樣呼叫的。
     * 這條路徑漏掉的話，症狀是「明明有人取消了，卻還是報不進去」。
     */
    #[Test]
    public function cancelling_through_the_model_also_notifies_the_gate(): void
    {
        $activity = Activity::factory()->withCapacity(1)->create();
        $occupant = User::factory()->create();
        $gate = app(SeatGate::class);

        $activity->join($occupant);

        $this->join($activity, User::factory()->create())->assertStatus(409);
        $this->assertSame(0, $gate->remaining($activity));

        $activity->cancel($occupant);

        $this->assertSame(1, $gate->remaining($activity));
    }

    private function join(Activity $activity, User $user)
    {
        return $this->actingAs($user)->postJson("/api/activities/{$activity->id}/registration");
    }
}
