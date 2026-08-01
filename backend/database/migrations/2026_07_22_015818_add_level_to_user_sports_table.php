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
        Schema::table('user_sports', function (Blueprint $table) {
            // 自評程度，1-10。可為 null：使用者可以先加入一項運動，之後再評分。
            $table->unsignedTinyInteger('level')->nullable()->after('sport_id');
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::table('user_sports', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
