<?php

namespace Tests\Support;

use App\Idempotency\IdempotencyRecord;
use App\Idempotency\IdempotencyStore;

/**
 * 包在真正的 store 外面，把每次呼叫記下來。
 *
 * 行為完全交給裡面那個實例，所以裝上它之後系統的表現不變 —— 它只回答一個問題：
 * 這個請求到底有沒有碰到冪等儲存。用「有沒有呼叫」而不是「有沒有發出某種 SQL」
 * 來問，測試才不會因為某條路由換了後端（database ↔ redis）就無聲失效。
 */
class RecordingIdempotencyStore implements IdempotencyStore
{
    /** @var list<string> 依序記下呼叫過的方法名。 */
    public array $calls = [];

    public function __construct(private IdempotencyStore $inner) {}

    public function forgetCalls(): void
    {
        $this->calls = [];
    }

    public function claim(string $scope, string $key, string $fingerprint): bool
    {
        $this->calls[] = 'claim';

        return $this->inner->claim($scope, $key, $fingerprint);
    }

    public function find(string $scope, string $key): ?IdempotencyRecord
    {
        $this->calls[] = 'find';

        return $this->inner->find($scope, $key);
    }

    public function complete(string $scope, string $key, int $status, ?string $body, ?string $contentType): void
    {
        $this->calls[] = 'complete';

        $this->inner->complete($scope, $key, $status, $body, $contentType);
    }

    public function release(string $scope, string $key): void
    {
        $this->calls[] = 'release';

        $this->inner->release($scope, $key);
    }
}
