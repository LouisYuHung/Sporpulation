<?php

namespace App\Idempotency;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * 依名稱取得 store，讓「用哪個後端」可以逐條路由決定。
 *
 * 這個選擇不是效能調校，而是風險分級：有第二層防線的寫入（報名有
 * unique(activity_id, user_id)）可以接受會失效的 Redis；沒有第二層的（開團）
 * 必須留在資料庫。詳見 routes/api.php。
 */
class IdempotencyStoreFactory
{
    public function __construct(private Container $container) {}

    public function make(?string $name = null): IdempotencyStore
    {
        $name ??= config('idempotency.default');

        $class = config("idempotency.stores.{$name}");

        // 路由上打錯字不該安靜地退回預設值 - 那會讓一條本來要用 database 的路由
        // 在沒人發現的情況下跑在 redis 上。
        if ($class === null) {
            throw new InvalidArgumentException("Idempotency store [{$name}] is not configured.");
        }

        return $this->container->make($class);
    }
}
