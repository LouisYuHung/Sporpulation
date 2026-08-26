<?php

namespace App\Console\Commands;

use App\Gate\SeatGate;
use App\Metrics\MetricRegistry;
use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * 拿 MySQL 的真相校正所有還活著的入場閘門。
 *
 * 閘門是「第二個真相來源」，兩份答案一定會漂移：請求扣了 token 卻在資料庫端失敗、
 * 使用者重試而 join() 直接回傳既有報名、行程在扣減與提交之間掛掉。這支指令的存在
 * 就是承認那件事，並且主動把它修回來。
 *
 * 漂移的兩個方向後果不對稱，所以指標也分開記：
 *
 *   too_strict   閘門比實際嚴格 —— 明明有位子卻拒絕使用者。這個要告警。
 *   too_generous 閘門比實際寬鬆 —— 只是削峰效果變差。這個只需要知道。
 */
class ReconcileGates extends Command
{
    protected $signature = 'gate:reconcile';

    protected $description = 'Correct every live admission gate against the database';

    public function handle(SeatGate $gate, MetricRegistry $metrics): int
    {
        $checked = 0;
        $drifted = 0;
        $tooStrict = 0;
        $tooGenerous = 0;

        foreach ($this->liveGateIds() as $activityId) {
            // 只要 id 就夠了 —— reconcile() 自己會去讀資料庫的當前狀態。這裡再讀
            // 一次只會多一趟往返，而且讀到的還是比較舊的那一份。
            $activity = new Activity;
            $activity->id = $activityId;

            $delta = $gate->reconcile($activity);
            $checked++;

            if ($delta === 0) {
                $metrics->increment('gate_reconciliations_total', ['result' => 'clean']);

                continue;
            }

            $drifted++;
            $metrics->increment('gate_reconciliations_total', ['result' => 'drifted']);

            if ($delta > 0) {
                $tooStrict += $delta;
                $metrics->increment('gate_drift_total', ['direction' => 'too_strict'], $delta);
            } else {
                $tooGenerous += -$delta;
                $metrics->increment('gate_drift_total', ['direction' => 'too_generous'], -$delta);
            }

            $this->line(sprintf(
                '  活動 %-6d 漂移 %+d 個名額（%s）',
                $activityId,
                $delta,
                $delta > 0 ? '偏嚴格，正在誤殺使用者' : '偏寬鬆，削峰效果變差',
            ));
        }

        $this->info(sprintf(
            '對帳完成：檢查 %d 個閘門，%d 個有漂移（偏嚴格 %d 個名額、偏寬鬆 %d 個名額）',
            $checked,
            $drifted,
            $tooStrict,
            $tooGenerous,
        ));

        return self::SUCCESS;
    }

    /**
     * 目前還活著的閘門對應到哪些活動。
     *
     * 用 SCAN 而不是 KEYS：Redis 是單執行緒的，KEYS * 會讓整台伺服器在掃描期間停止
     * 服務所有人。對帳是背景工作卻拖垮前景流量，那是最糟的一種取捨。
     *
     * cursor 必須從 null 開始，不能是 0 —— phpredis 把 0 當成「這輪迭代已經結束」，
     * 於是第一次呼叫就回傳 false。那個錯誤的症狀是這支指令每次都回報「檢查了 0 個
     * 閘門，一切正常」：綠燈、沒有錯誤、什麼都沒做。
     *
     * @return iterable<int>
     */
    private function liveGateIds(): iterable
    {
        $connection = Redis::connection((string) config('gate.connection'));

        // Laravel 的 Redis 連線會自動替 key 加前綴，但 SCAN 回傳的是伺服器上的
        // 完整 key，前綴要自己剝掉。
        $prefix = (string) config('database.redis.options.prefix');
        $cursor = null;

        do {
            $result = $connection->scan($cursor, ['match' => $prefix.'gate:activity:*', 'count' => 200]);

            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;

            foreach ($keys as $key) {
                $id = (int) substr((string) $key, strlen($prefix.'gate:activity:'));

                if ($id > 0) {
                    yield $id;
                }
            }
        } while ((int) $cursor !== 0);
    }
}
