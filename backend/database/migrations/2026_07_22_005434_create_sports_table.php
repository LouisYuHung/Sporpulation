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
        Schema::create('sports', function (Blueprint $table) {
            $table->id();
            $table->json('name');
        });
    }

    /**
     * 還原遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('sports');
    }
};
