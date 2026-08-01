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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The client's key is hashed rather than stored as sent: it is an
            // opaque token, and nothing here needs to read it back.
            $table->string('key_hash', 32);

            // Hash of method + path + body, so reusing a key for a different
            // request is caught instead of answered with the wrong response.
            $table->string('fingerprint', 32);

            // Null until the request finishes. A second request finding null
            // knows the first is still in flight rather than done.
            $table->unsignedSmallInteger('status')->nullable();
            $table->mediumText('body')->nullable();
            $table->string('content_type')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            // The claim: an insert that violates this is how a duplicate
            // request discovers it lost the race (see EnsureIdempotentRequest).
            $table->unique(['user_id', 'key_hash']);

            // Pruning scans by expiry alone.
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
