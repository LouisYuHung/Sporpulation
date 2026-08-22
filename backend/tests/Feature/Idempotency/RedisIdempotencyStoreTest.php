<?php

namespace Tests\Feature\Idempotency;

use App\Idempotency\IdempotencyStore;
use App\Idempotency\RedisIdempotencyStore;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisIdempotencyStoreTest extends IdempotencyStoreContract
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection('idempotency')->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
        }
    }

    protected function store(): IdempotencyStore
    {
        return new RedisIdempotencyStore;
    }
}
