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
        Schema::create('user_area_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            // A user tags any given district at most once. The city is reached
            // through districts.city_id, so it is not duplicated here.

            // The unique index covers soft-deleted rows too, so re-adding a
            // removed area restores the existing row rather than inserting a
            // second one (see User::addArea()).
            $table->unique(['user_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_area_tags');
    }
};
