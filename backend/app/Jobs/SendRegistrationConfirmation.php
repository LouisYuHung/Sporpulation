<?php

namespace App\Jobs;

use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyStoreFactory;
use App\Models\ActivityRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 報名成功後寄出確認信。
 *
 * 這件事刻意不留在請求路徑上：信寄不出去，使用者的名額還是他的。留在同步路徑只會
 * 讓報名 API 的延遲被郵件伺服器綁架 - 對方慢三秒，你的 API 就慢三秒。
 */
class SendRegistrationConfirmation implements ShouldQueue
{
    use Queueable;

    /**
     * 去重紀錄的命名空間。與 API 層的冪等紀錄共用同一個 Redis 後端，但 scope 不同 -
     * 那邊的 scope 是使用者 id。
     */
    private const SCOPE = 'job:registration-confirmation';

    /**
     * 只帶 id，不帶整個模型、也不帶 email。
     *
     * Job 從入列到執行之間有時間差，期間報名可能已被取消、使用者可能改了 email。
     * 帶 id 的意思是「執行時再看現在的狀態」，帶快照則是「用入列當下的狀態」——
     * 對確認信來說前者才對。
     */
    public function __construct(public int $registrationId) {}

    public function handle(IdempotencyStoreFactory $stores): void
    {
        $registration = ActivityRegistration::with(['activity', 'user'])
            ->find($this->registrationId);

        if ($registration === null || ! $registration->isConfirmed()) {
            return;
        }

        $store = $stores->make('redis');

        // 佇列是 at-least-once：worker 中途掛掉、或處理時間超過 retry_after，同一個
        // Job 會被再投一次。uuid 在重投之間不變，因此它標識的是「同一次投遞」——
        // 而不是「同一筆報名」。用 registrationId 當 key 會誤殺「取消後重新報名」，
        // 因為 join() 在那個情況下沿用同一列、id 沒有變。
        $key = (string) $this->job?->uuid();

        if ($this->handled($store, $key)) {
            return;
        }

        Mail::raw(
            "您已成功報名「{$registration->activity->title}」。",
            fn ($message) => $message
                ->to($registration->user->email)
                ->subject('報名確認'),
        );

        // 標記在寄信「之後」，不是之前。
        //
        // 先佔位是原子的、絕不重複，但當機落在佔位與寄信之間時，重試會被自己的
        // 去重擋掉，信永遠不會寄出。後標記則相反：當機時重試會重寄一封。
        //
        // 對確認信來說重複遠比遺失好 —— 而這正是 at-least-once 的立場：佇列承諾
        // 「至少一次」，消費者就該跟它站在同一邊。付款那類 Job 的答案不一樣，
        // 因此那時要重新問一次「哪一種失敗比較痛」。
        $this->markHandled($store, $key);
    }

    /**
     * Redis 不可達時當作「沒處理過」，讓信照寄。
     *
     * 這跟上面選擇後標記是同一個立場：寧可重複也不要遺失。去重是最佳化，不是正確性
     * 保證 —— 它自己故障時就該讓路，而不是把整個 Job 拖進重試迴圈。
     */
    private function handled(IdempotencyStore $store, string $key): bool
    {
        try {
            return $store->find(self::SCOPE, $key) !== null;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function markHandled(IdempotencyStore $store, string $key): void
    {
        try {
            $store->claim(self::SCOPE, $key, static::class);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
