<?php

namespace Tests\Feature\Idempotency;

use App\Idempotency\DatabaseIdempotencyStore;
use App\Idempotency\IdempotencyStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseIdempotencyStoreTest extends IdempotencyStoreContract
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // idempotency_keys.user_id 有外鍵約束，因此 contract 用的 scope '7' 和 '8'
        // 必須真的存在 —— 這是這個後端額外的限制，見 DatabaseIdempotencyStore 的
        // class docblock。
        User::factory()->count(8)->create();
    }

    protected function store(): IdempotencyStore
    {
        return new DatabaseIdempotencyStore;
    }
}
