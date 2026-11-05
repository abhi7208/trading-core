<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra table (not in the brief): remembers the exact response we sent for an
     * Idempotency-Key, so a retry gets the ORIGINAL response back byte-for-byte, and a
     * different payload under the same key can be detected via request_hash.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->char('request_hash', 64);          // sha256 of the normalized payload
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('response_status');
            // TEXT, not JSON: MySQL's JSON type re-orders keys, and a replay must return the
            // original response exactly as it was first sent.
            $table->longText('response_body');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'key']); // the lookup on every POST /orders
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
