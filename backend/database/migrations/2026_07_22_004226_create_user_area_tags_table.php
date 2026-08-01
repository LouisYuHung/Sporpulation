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
        Schema::create('user_area_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            // 同一位使用者對任一行政區最多只會標記一次。縣市透過
            // districts.city_id 取得，因此不在這裡重複儲存。

            // 這個唯一索引同樣涵蓋軟刪除的資料列，因此重新加入曾移除的地區會還原
            // 既有的資料列，而不是插入第二筆（見 User::addArea()）。
            $table->unique(['user_id', 'district_id']);
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('user_area_tags');
    }
};
