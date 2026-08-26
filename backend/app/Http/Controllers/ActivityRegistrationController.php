<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Exceptions\ActivityFullException;
use App\Gate\GateDecision;
use App\Gate\SeatGate;
use App\Http\Resources\ActivityRegistrationResource;
use App\Http\Resources\ActivityResource;
use App\Jobs\SendRegistrationConfirmation;
use App\Metrics\MetricRegistry;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Throwable;

class ActivityRegistrationController extends Controller
{
    /**
     * 已登入使用者的報名紀錄。預設只列出已確認的；帶入 ?status= 可改看已取消的。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'integer', Rule::enum(RegistrationStatus::class)],
        ]);

        $registrations = $request->user()->registrations()
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('activity_registrations.status', $filters['status']),
                fn ($query) => $query->confirmed(),
            )
            ->with(['activity' => fn ($query) => $query->with(ActivityController::relations($request))])
            // 依使用者實際要下場的時間排序，而不是依報名的時間。
            ->join('activities', 'activities.id', '=', 'activity_registrations.activity_id')
            ->orderBy('activities.starts_at')
            ->select('activity_registrations.*')
            ->get();

        return ActivityRegistrationResource::collection($registrations);
    }

    /**
     * 佔用一個名額。
     *
     * 具冪等性：重送同一個請求會回傳同一個活動與同樣的 joined_count，因為報名紀錄
     * 本身是以 (activity_id, user_id) 為鍵。活動額滿或已開始時回應 409。
     */
    public function store(
        Request $request,
        Activity $activity,
        MetricRegistry $metrics,
        SeatGate $gate,
    ): JsonResponse {
        $decision = $gate->admit($activity);

        $metrics->increment('gate_decisions_total', ['decision' => $decision->value]);

        // 這一行就是削峰：被擋下的請求連一句 SQL 都不會產生。
        //
        // 丟的是同一個 ActivityFullException —— 對使用者來說結果一模一樣，
        // 「誰擋的」是內部細節，只存在於指標裡。
        if ($decision === GateDecision::Shed) {
            throw new ActivityFullException;
        }

        // 量測放在閘門之後：被削掉的請求根本沒碰到名額，混進這個分佈會讓 p99 被
        // 一堆「其實什麼都沒做」的極快回應拉低。
        $startedAt = hrtime(true);
        $outcome = 'rejected';

        try {
            $registration = $activity->join($request->user());
            $outcome = 'granted';
        } catch (Throwable $e) {
            // 沒佔到名額就把預扣的 token 還回去。
            //
            // 少還一次，閘門就永遠比實際嚴格一格，而那一格會持續誤殺使用者直到
            // 閘門過期為止 —— 中間不會有任何錯誤訊息。這是整個 M6 最容易出錯、
            // 也最難察覺的地方。
            if ($decision->consumedToken()) {
                $gate->release($activity);
            }

            throw $e;
        } finally {
            $metrics->observe(
                'seat_claim_duration_seconds',
                ['outcome' => $outcome],
                (hrtime(true) - $startedAt) / 1e9,
            );
        }

        // 非同步邊界就在這一行：上面那句影響資料正確性，必須同步完成；下面這封信
        // 不影響，因此交給佇列。判準是「這件事失敗了，使用者的報名還算不算數」。
        SendRegistrationConfirmation::dispatch($registration->id);

        return $this->activity($request, $activity)->response()->setStatusCode(201);
    }

    /**
     * 歸還名額。具冪等性：重複取消，或在從未報名的情況下取消，都不會有任何作用。
     */
    public function destroy(Request $request, Activity $activity): ActivityResource
    {
        $activity->cancel($request->user());

        // 閘門的歸還在 Activity::releaseSeat() 裡 —— 那是 joined_count 真正減少的
        // 那一行，也是唯一能保證「不論誰呼叫都會通知閘門」的位置。
        return $this->activity($request, $activity);
    }

    /**
     * 回傳活動的當前狀態，讓用戶端可以直接從這個寫入回應更新 joined_count 與呼叫者
     * 自己的報名狀態。
     *
     * 這裡選擇重新讀取而不是沿用記憶體中的值：請求之間會有其他人報名與取消，使用者
     * 看到的數字應該是最新的。
     */
    private function activity(Request $request, Activity $activity): ActivityResource
    {
        return new ActivityResource(
            $activity->refresh()->load(ActivityController::relations($request))
        );
    }
}
