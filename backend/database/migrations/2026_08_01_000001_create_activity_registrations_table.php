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
        Schema::create('activity_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // App\Enums\RegistrationStatus. Cancelling keeps the row and flips
            // the status instead of deleting, so the unique index below still
            // recognises a returning user and the history stays auditable.
            $table->unsignedTinyInteger('status');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // The idempotency key for joining: a replayed request collides here
            // rather than taking a second seat (see Activity::join()).
            $table->unique(['activity_id', 'user_id']);

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_registrations');
    }
};
