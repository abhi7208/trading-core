<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            // UNIQUE: we do not do partial fills, so an order has at most one execution.
            // This is a second, database-level guarantee that an order can never be settled twice.
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            // Copied from the order so the market summary can aggregate without a join.
            $table->unsignedBigInteger('asset_id');
            $table->decimal('price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->decimal('notional', 20, 8); // price x quantity, stored so SUM() is cheap
            $table->decimal('fee', 20, 8)->default(0);
            $table->dateTime('executed_at', 6);

            // Market summary: "volume per asset in the last 24h". All three columns are in the
            // index, so MySQL answers from the index alone without reading table rows.
            $table->index(['executed_at', 'asset_id', 'notional']);
        });

        DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_price_positive CHECK (price > 0)');
        DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_fee_non_negative CHECK (fee >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
