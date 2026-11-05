<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable(); // null = the system (worker, scheduler)
            $table->string('action', 64);                       // e.g. order.filled
            $table->string('subject_type', 64);                 // e.g. order
            $table->unsignedBigInteger('subject_id');
            $table->json('context');                            // before/after state, correlation id
            $table->string('ip_address', 45)->nullable();       // 45 chars fits IPv6
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']); // "audit history of order X"
            $table->index(['actor_id', 'created_at']);     // "what did user X do, newest first"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
