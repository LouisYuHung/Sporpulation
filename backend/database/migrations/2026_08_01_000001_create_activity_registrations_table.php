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
        Schema::create('activity_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 對應 App\Enums\RegistrationStatus。取消時保留資料列並翻轉狀態而非
            // 刪除，如此下方的唯一索引仍能認出回頭報名的使用者，歷程也保有可稽核性。
            $table->unsignedTinyInteger('status');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // 報名的冪等鍵：重送的請求會在這裡發生衝突，而不是佔走第二個名額
            // （見 Activity::join()）。
            $table->unique(['activity_id', 'user_id']);

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_registrations');
    }
};
