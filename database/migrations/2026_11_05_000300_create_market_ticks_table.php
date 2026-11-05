<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_ticks', function (Blueprint $table) {
            $table->id();
            // No foreign key on purpose: this is a high-volume append-only history table that we
            // want to partition by time later, and MySQL partitioned tables cannot have foreign keys.
            $table->unsignedBigInteger('asset_id');
            $table->decimal('price', 20, 8);
            $table->dateTime('recorded_at', 6); // microsecond precision

            // Serves "first/last price of asset X in a time window" (24h change in the market
            // summary). price is included so MySQL answers from the index alone ("covering").
            $table->index(['asset_id', 'recorded_at', 'price'], 'market_ticks_asset_time_price_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_ticks');
    }
};
