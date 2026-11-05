<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->unique();       // lookups by symbol from the API
            $table->string('name');
            $table->unsignedTinyInteger('price_precision');    // max decimals allowed in a price
            $table->unsignedTinyInteger('quantity_precision'); // max decimals allowed in a quantity
            $table->boolean('is_tradable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
