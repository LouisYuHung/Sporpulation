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
        Schema::create('user_sports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sport_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            // 這個唯一索引同樣涵蓋軟刪除的資料列，因此重新加入曾移除的運動會還原
            // 既有的資料列，而不是插入第二筆（見 User::addSport()）。
            $table->unique(['user_id', 'sport_id']);
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sports');
    }
};
