<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_sports', function (Blueprint $table) {
            // Self-rated skill, 1-10. Nullable: a user may add a sport
            // without rating themselves yet.
            $table->unsignedTinyInteger('level')->nullable()->after('sport_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_sports', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
