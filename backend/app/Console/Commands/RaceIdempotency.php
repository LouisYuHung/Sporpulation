<?php

namespace App\Console\Commands;

use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyStoreFactory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * 驗證 IdempotencyStore::claim() 在真正併發下只會有一個贏家。
 *
 * 這是介面唯一不可協商的條款，也是唯一測試套件驗不到的 - 單一行程裡的循序呼叫，
 * 兩次 claim 之間不可能被插隊。兩個後端用完全不同的機制達成同一句保證：資料庫靠
 * unique(user_id, key_hash)，Redis 靠單執行緒執行的 Lua。
 */
class RaceIdempotency extends Command
{
    protected $signature = 'idempotency:race
                            {--store=redis : 要驗的後端（database / redis）}
                            {--racers=50 : 同時搶同一把 key 的行程數}';

    protected $description = 'Fork simultaneous claims on one key and verify exactly one wins';

    public function handle(IdempotencyStoreFactory $stores): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('This command needs the pcntl extension.');

            return self::FAILURE;
        }

        if (app()->isProduction()) {
            $this->error('This command writes throwaway records. Not in production.');

            return self::FAILURE;
        }

        $name = (string) $this->option('store');
        $racers = (int) $this->option('racers');

        $store = $stores->make($name);

        // 資料庫後端的 user_id 有外鍵約束，scope 必須是真的存在的使用者。
        // Redis 沒有這個限制 - 這是兩個實作實際上不對等的地方之一。
        $scope = $name === 'database'
              ? (string) User::factory()->create()->id
              : 'race-'.Str::random();

        // 每次跑用新的 key，否則上一輪的紀錄還佔著，所有人都會落敗。
        $key = 'race-'.Str::random(20);

        $codes = $this->race(
            $racers,
            fn () => $this->attempt($store, $scope, $key),
        );

        $won = count(array_keys($codes, 0));
        $lost = count(array_keys($codes, 1));
        $errored = count(array_keys($codes, 2));

        $ok = $won === 1 && $errored === 0;

        $this->line(sprintf(
            'store=%-8s racers=%d  搶到=%d  落敗=%d  錯誤=%d  %s',
            $name, $racers, $won, $lost, $errored,
            $ok ? 'PASS' : 'FAIL',
        ));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 「在競爭中落敗」與「根本沒跑起來」必須分開計數。
     *
     * 兩者都是非零的結束代碼，混在一起的話，連線耗盡會偽裝成「大家都乖乖落敗」，
     * 指令就會在系統其實撐不住的時候印出 PASS —— 200 個 racer 打 MySQL 時就是
     * 這樣，31 個行程死於 max_connections，卻被算進落敗裡。
     *
     * CheckSeatConcurrency 用的是同一個約定（0 = 成功、1 = 乾淨的落敗、2 = 錯誤）。
     */
    private function attempt(IdempotencyStore $store, string $scope, string $key): int
    {
        try {
            return $store->claim($scope, $key, 'fp') ? 0 : 1;
        } catch (Throwable) {
            return 2;
        }
    }

    /**
     * 在同一個實際時間點同時執行 $count 份 $work，並收集它們的結束代碼。
     *
     * @param  callable(int): int  $work
     * @return list<int>
     */
    private function race(int $count, callable $work): array
    {
        $startAt = microtime(true) + 1.0;
        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // fork 會把已開啟的 socket 複製給每個子行程，多個行程共用同一條
                // 連線會讀到彼此的回應。兩個後端都跑得到，所以兩個都清掉。
                DB::purge();
                Redis::purge('idempotency');

                usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

                exit($work($i));
            }

            $pids[] = $pid;
        }

        $codes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }

        return $codes;
    }
}
