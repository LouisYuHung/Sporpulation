<?php

namespace App\Gate;

use App\Models\Activity;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * 入場閘門：在扣 activities.joined_count 之前，先擋掉必定會失敗的請求。
 *
 * 這一層引入了第二個「還有幾個名額」的答案，兩份答案一定會漂移。關鍵在於漂移的
 * 兩個方向後果完全不同：
 *
 *   閘門比實際寬鬆 → 多放一些請求進 MySQL，被條件式 UPDATE 擋下。無害。
 *   閘門比實際嚴格 → 明明有位子卻拒絕使用者。這才是真正的 bug。
 *
 * 所有設計都倒向「寧可寬鬆」。超賣仍然不可能 —— MySQL 那層完全沒有動。
 */
class SeatGate
{
    /**
     * 檢查加遞減，一次原子操作。跟限流那支 Lua 是同一個精神。
     *
     * 回傳 -1 代表閘門還不存在。Lua 讀不到 MySQL，所以「從資料庫建起來」這件事
     * 只能交回給 PHP 做。
     */
    private const ADMIT = <<<'LUA'
        local remaining = redis.call('GET', KEYS[1])

        if not remaining then
            return -1
        end

        if tonumber(remaining) <= 0 then
            return 0
        end

        redis.call('DECR', KEYS[1])

        return 1
    LUA;

    private const RELEASE = <<<'LUA'
        local remaining = redis.call('GET', KEYS[1])

        -- 閘門已經過期了。憑空 INCR 會造出一個沒有 TTL、而且與真實名額毫無關係的
        -- 閘門 —— 那比沒有閘門更糟。
        if not remaining then
            return -1
        end

        -- 歸還後會超過容量：一定是某個 token 被還了兩次。這是 Activity::releaseSeat()
        -- 那個 `where joined_count > 0` 的鏡像 —— 同一種安全網，同一個理由。
        if tonumber(remaining) >= tonumber(ARGV[1]) then
            return -2
        end

        return redis.call('INCR', KEYS[1])
    LUA;

    private const RECONCILE = <<<'LUA'
        local remaining = redis.call('GET', KEYS[1])

        -- 沒有閘門就不必對帳。這裡刻意不建立 —— 建立是「第一個請求抵達」的事，
        -- 對帳只負責校正已經存在的東西。憑空建起來會讓每一個沒有流量的活動都
        -- 佔著記憶體。
        if not remaining then
            return 0
        end

        local truth = tonumber(ARGV[1])
        local before = tonumber(remaining)

        if before == truth then
            return 0
        end

        -- KEEPTTL：閘門的壽命從建立那一刻算起，對帳不該替它續命。否則熱門活動的
        -- 閘門永遠不會過期重建，也就少了一條自動回頭看真相的路徑。
        redis.call('SET', KEYS[1], truth, 'KEEPTTL')

        return truth - before
    LUA;

    public function __construct(private readonly RedisFactory $redis) {}

    public function admit(Activity $activity): GateDecision
    {
        if (! config('gate.enabled')) {
            return GateDecision::Unknown;
        }

        try {
            $result = $this->run(self::ADMIT, $activity);

            // 閘門不存在就建起來再問一次。只重試一次 —— 若第二次仍然說不存在，
            // 代表有別的問題（例如 TTL 設成 0），那時放行比無限重試好。
            if ($result === -1) {
                $this->open($activity);
                $result = $this->run(self::ADMIT, $activity);
            }

            return match ($result) {
                1 => GateDecision::Admitted,
                0 => GateDecision::Shed,
                default => GateDecision::Unknown,
            };
        } catch (Throwable $e) {
            report($e);

            return GateDecision::Unknown;
        }
    }

    /**
     * 歸還一個 token。只有 GateDecision::Admitted 才能呼叫。
     */
    public function release(Activity $activity): void
    {
        if (! config('gate.enabled')) {
            return;
        }

        try {
            $this->run(self::RELEASE, $activity, [(string) $activity->capacity]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * 閘門目前認為還剩幾個名額。null 代表閘門不存在。
     */
    public function remaining(Activity $activity): ?int
    {
        $value = $this->connection()->get($this->key($activity));

        return $value === null ? null : (int) $value;
    }

    /**
     * 拿資料庫的真相校正閘門，回傳修正的差額。
     *
     * 正數 = 閘門原本比實際嚴格（正在誤殺使用者，這是真正要修的方向）。
     * 負數 = 閘門原本比實際寬鬆（只是削峰效果變差）。
     * 0    = 本來就準，或這個活動根本沒有閘門。
     *
     * 「讀資料庫」與「寫 Redis」之間的空隙不需要保護：這段期間發生的扣減會被覆蓋
     * 掉，結果是閘門比實際寬鬆 —— 而寫進去的值就是資料庫此刻的空位數，所以它
     * 永遠不可能變得比真相嚴格。往安全的方向偏，因此不需要 CAS 或版本號。
     */
    public function reconcile(Activity $activity): int
    {
        $current = Activity::query()
            ->whereKey($activity->getKey())
            ->first(['capacity', 'joined_count']);

        if ($current === null) {
            return 0;
        }

        $truth = max(0, (int) $current->capacity - (int) $current->joined_count);

        return $this->run(self::RECONCILE, $activity, [(string) $truth]);
    }

    /**
     * 從資料庫把閘門建起來。
     *
     * NX 不能省：兩個請求同時發現閘門不存在時，兩邊都會去讀資料庫。讓後到的那個
     * 覆蓋先到的沒有好處，反而會把先到那個已經扣掉的 token 憑空補回去。
     *
     * 用 PHP 算差額而不是 SQL 的 GREATEST：那是 MySQL 的函式，sqlite 沒有，
     * 而測試跑在 sqlite 上。
     */
    private function open(Activity $activity): void
    {
        $current = Activity::query()
            ->whereKey($activity->getKey())
            ->first(['capacity', 'joined_count']);

        if ($current === null) {
            return;
        }

        $free = max(0, (int) $current->capacity - (int) $current->joined_count);

        $this->connection()->set(
            $this->key($activity),
            $free,
            'EX',
            (int) config('gate.ttl'),
            'NX',
        );
    }

    private function run(string $script, Activity $activity, array $args = []): int
    {
        return (int) $this->connection()->eval($script, 1, $this->key($activity), ...$args);
    }

    private function key(Activity $activity): string
    {
        return 'gate:activity:'.$activity->getKey();
    }

    private function connection()
    {
        return $this->redis->connection(config('gate.connection'));
    }
}
