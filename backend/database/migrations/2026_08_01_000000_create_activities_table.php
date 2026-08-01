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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sport_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->string('location');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity');

            // 已確認報名數的反正規化計數。activity_registrations 仍是唯一事實
            // 來源；這個欄位存在的目的，是讓佔用名額能用單一條件式 UPDATE 完成
            //（見 Activity::claimSeat()）。使用 unsigned 也能讓下溢直接爆錯，
            // 而不是無聲地繞回成一個極大的數字。
            $table->unsignedSmallInteger('joined_count')->default(0);

            $table->timestamps();

            // 列表查詢一律是「即將開始，可再以運動或行政區篩選」，因此 starts_at
            // 都排在兩個篩選欄位之後。
            $table->index(['sport_id', 'starts_at']);
            $table->index(['district_id', 'starts_at']);
            $table->index('starts_at');
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
