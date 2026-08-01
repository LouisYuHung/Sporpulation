<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 執行遷移。
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 用戶端的 key 會先雜湊再存，而不是原樣保存：它是一個不透明的權杖，
            // 這裡沒有任何地方需要把它讀回來。
            $table->string('key_hash', 32);

            // method + path + body 的雜湊值，讓同一把 key 被用在不同請求時能被
            // 抓出來，而不是回一個錯誤的回應。
            $table->string('fingerprint', 32);

            // 在請求完成之前都是 null。第二個請求若讀到 null，就知道第一個仍在
            // 進行中而非已經完成。
            $table->unsignedSmallInteger('status')->nullable();
            $table->mediumText('body')->nullable();
            $table->string('content_type')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            // 佔位機制：插入時違反這個索引，正是重複請求得知自己在競爭中落敗的
            // 方式（見 EnsureIdempotentRequest）。
            $table->unique(['user_id', 'key_hash']);

            // 清理排程只會依過期時間掃描。
            $table->index('expires_at');
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
