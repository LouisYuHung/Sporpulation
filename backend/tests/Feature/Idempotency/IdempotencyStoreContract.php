<?php

namespace Tests\Feature\Idempotency;

use App\Idempotency\IdempotencyStore;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * IdempotencyStore 的實作必須共同滿足的約定。
 *
 * 每個後端都有子類別，跑的是同一組斷言 - 這才是介面真正的定義。介面本身只規定
 * 方法簽章，那些 PHP 幫你檢查；「佔位失敗代表別人拿到了」「complete 不延長過期
 * 時間」這些行為只能靠測試守住。
 */
abstract class IdempotencyStoreContract extends TestCase
{
    abstract protected function store(): IdempotencyStore;

    #[Test]
    public function the_first_claim_wins_and_the_second_is_refused(): void
    {
        $store = $this->store();

        $this->assertTrue($store->claim('7', 'key-abcdefgh', 'fp-A'));
        $this->assertFalse($store->claim('7', 'key-abcdefgh', 'fp-B'));
    }

    #[Test]
    public function a_claimed_record_reads_back_as_in_progress(): void
    {
        $store = $this->store();

        $store->claim('7', 'key-abcdefgh', 'fp-A');

        $record = $store->find('7', 'key-abcdefgh');

        $this->assertNotNull($record);
        $this->assertSame('fp-A', $record->fingerprint);
        $this->assertNull($record->status);
        $this->assertTrue($record->isInProgress());
    }

    #[Test]
    public function completing_a_record_makes_the_result_readable(): void
    {
        $store = $this->store();

        $store->claim('7', 'key-abcdefgh', 'fp-A');
        $store->complete('7', 'key-abcdefgh', 201, '{"id":1}', 'application/json');

        $record = $store->find('7', 'key-abcdefgh');

        $this->assertFalse($record->isInProgress());
        $this->assertSame(201, $record->status);
        $this->assertSame('{"id":1}', $record->body);
        $this->assertSame('application/json', $record->contentType);

        // 指紋不能在 complete 時被弄丟 - replay() 靠它辨認「同一把 key 用在不同請求」。
        $this->assertSame('fp-A', $record->fingerprint);
    }

    #[Test]
    public function releasing_frees_the_key(): void
    {
        $store = $this->store();

        $store->claim('7', 'key-abcdefgh', 'fp-A');
        $store->release('7', 'key-abcdefgh');

        $this->assertNull($store->find('7', 'key-abcdefgh'));

        // 釋放的意義就是這把 key 可以重新被佔用。
        $this->assertTrue($store->claim('7', 'key-abcdefgh', 'fp-B'));
    }

    #[Test]
    public function scopes_do_not_collide(): void
    {
        $store = $this->store();

        $this->assertTrue($store->claim('7', 'key-abcdefgh', 'fp-A'));

        // 兩個使用者剛好選到同一把 key 時不能互相衝突。
        $this->assertTrue($store->claim('8', 'key-abcdefgh', 'fp-B'));

        $this->assertSame('fp-A', $store->find('7', 'key-abcdefgh')->fingerprint);
        $this->assertSame('fp-B', $store->find('8', 'key-abcdefgh')->fingerprint);
    }

    #[Test]
    public function an_unknown_key_is_not_found(): void
    {
        $this->assertNull($this->store()->find('7', 'key-never-used'));
    }
}
