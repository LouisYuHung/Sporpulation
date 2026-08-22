<?php

namespace App\Http\Middleware;

use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\RequestInProgressException;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyStoreFactory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 當寫入請求帶著相同的 Idempotency-Key 重試時，重播原本的回應。
 *
 * 這是一道通用的安全網，獨立於個別端點自己已經提供的保證之外。報名活動因為有
 * unique(activity_id, user_id) 而本身就具冪等性；而 key 保護的是那些沒有這種天然
 * 唯一鍵的寫入，同時讓重試風暴根本不會打到領域邏輯。
 *
 * 以路由為單位選擇啟用：沒有帶這個標頭的請求會直接通過。
 */
class EnsureIdempotentRequest
{
    /**
     * 這些回應不會被儲存，而是直接釋放 key，因為它們代表什麼事都沒發生、之後再試
     * 有可能會成功。詳見 remember()。
     */
    private const RELEASED_STATUSES = [
        Response::HTTP_CONFLICT,
        Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public function __construct(private IdempotencyStoreFactory $stores) {}

    public function handle(Request $request, Closure $next, ?string $storeName = null): Response
    {
        $key = $request->header(config('idempotency.header'));
        $user = $request->user();

        if ($key === null || $user === null || $request->isMethodSafe()) {
            return $next($request);
        }

        $this->validateKey($key);

        // 後端由路由決定（例如 idempotent:redis），沒指定就用 config 的預設值。
        // 這個選擇是風險分級，不是效能調校 - 見 routes/api.php。
        $store = $this->stores->make($storeName);

        $scope = (string) $user->getAuthIdentifier();
        $fingerprint = $this->fingerprint($request);

        // 佔位被別人搶走了：不是第一個請求還在執行中，就是它已經完成、答案已存檔。
        if (! $store->claim($scope, $key, $fingerprint)) {
            return $this->replay($store, $scope, $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // 路由管線通常會在例外傳到這裡之前就把它轉成回應，因此這裡只涵蓋漏網
            // 之魚。無論如何佔位都必須釋放，否則用戶端重試時會一直收到「處理中」，
            // 直到紀錄過期為止。
            $store->release($scope, $key);

            throw $e;
        }

        $this->remember($store, $scope, $key, $response);

        return $response;
    }

    /**
     * 以別人已經寫下的紀錄來回應。
     */
    private function replay(IdempotencyStore $store, string $scope, string $key, string $fingerprint): Response
    {
        $record = $store->find($scope, $key);

        // 在佔位失敗到這次讀取之間紀錄被刪除了 - 原本持有者已經放棄，因此呼叫端
        // 大可從頭重試一次。
        if ($record === null) {
            throw new RequestInProgressException;
        }

        // 相同的 key 卻是不同的請求：這一定是用戶端的錯誤，絕不能靠重播一個不相干
        // 的回應來粉飾。
        if ($record->fingerprint !== $fingerprint) {
            throw new IdempotencyKeyReusedException;
        }

        if ($record->isInProgress()) {
            throw new RequestInProgressException;
        }

        return response($record->body, $record->status)
            ->withHeaders(array_filter([
                'Content-Type' => $record->contentType,

                // 讓用戶端能分辨這是重播還是全新的結果 - 除錯時很有用，
                // 其餘情況也無害。
                'Idempotent-Replay' => 'true',
            ]));
    }

    /**
     * 儲存執行結果，讓重試時不必重新執行就能得到答案。
     *
     * 只有那些「不該讓重試再做一次」的結果才值得儲存。409 代表請求在競爭中落敗、
     * 什麼都沒改變 - 例如活動額滿 - 而 429 代表它根本沒被放行。儲存這兩者都會讓
     * 用戶端在整個 TTL 期間被釘死在一個過時的「不行」上，即使名額後來釋出也一樣，
     * 因此直接丟棄紀錄，讓重試取得全新的答案。5xx 也是如此，因為結果根本無從得知。
     */
    private function remember(IdempotencyStore $store, string $scope, string $key, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 500 || in_array($status, self::RELEASED_STATUSES, true)) {
            $store->release($scope, $key);

            return;
        }

        $store->complete(
            $scope,
            $key,
            $status,
            $response->getContent(),
            $response->headers->get('Content-Type'),
        );
    }

    /**
     * 標識這個請求問了什麼，好讓同一把 key 被用在不同呼叫時能被抓出來，而不是回
     * 一個錯誤的回應。
     */
    private function fingerprint(Request $request): string
    {
        return $this->hash(implode('|', [
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));
    }

    private function validateKey(string $key): void
    {
        $length = strlen($key);

        if ($length < config('idempotency.min_length') || $length > config('idempotency.max_length')) {
            throw ValidationException::withMessages([
                config('idempotency.header') => __('messages.idempotency.invalid'),
            ]);
        }
    }

    private function hash(string $value): string
    {
        return hash('xxh128', $value);
    }
}
