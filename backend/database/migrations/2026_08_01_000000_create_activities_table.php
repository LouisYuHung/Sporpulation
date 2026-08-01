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

            // Denormalised count of confirmed registrations. activity_registrations
            // stays the source of truth; this column exists so a seat can be
            // claimed with one conditional UPDATE (see Activity::claimSeat()).
            // unsigned also makes an underflow fail loudly rather than silently
            // wrap into a huge number.
            $table->unsignedSmallInteger('joined_count')->default(0);

            $table->timestamps();

            // Listing queries are always "upcoming, optionally narrowed by
            // sport or district", so starts_at trails both filter columns.
            $table->index(['sport_id', 'starts_at']);
            $table->index(['district_id', 'starts_at']);
            $table->index('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
