<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();               // public id used in URLs
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('side', 4);                    // BUY / SELL (PHP enum)
            $table->string('type', 6);                    // LIMIT / MARKET (PHP enum)
            $table->decimal('quantity', 20, 8);
            $table->decimal('limit_price', 20, 8)->nullable();
            // Exact cash locked for a BUY at acceptance time. Released/used exactly once.
            $table->decimal('reserved_amount', 20, 8)->default(0);
            $table->string('status', 12);                 // PHP enum
            $table->string('idempotency_key', 64);
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Same Idempotency-Key from the same user can only ever create ONE order.
            $table->unique(['user_id', 'idempotency_key']);
            // GET /orders?status=...   (InnoDB appends the primary key, so this is really
            // (user_id, status, id) and also serves the cursor ORDER BY id)
            $table->index(['user_id', 'status']);
            // GET /orders?asset=...
            $table->index(['user_id', 'asset_id']);
            // Price-tick sweep: "PENDING LIMIT BUYs for asset X with limit_price >= P"
            $table->index(['asset_id', 'status', 'side', 'limit_price']);
            // Expiry command: "PENDING orders whose expires_at has passed"
            $table->index(['status', 'expires_at']);
            // (No index for "filled in the last 24h": the summary counts executions instead,
            // using the executions covering index. See DECISIONS.md.)
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_limit_price_positive CHECK (limit_price IS NULL OR limit_price > 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_limit_requires_price CHECK (type <> 'LIMIT' OR limit_price IS NOT NULL)");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_reserved_non_negative CHECK (reserved_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
