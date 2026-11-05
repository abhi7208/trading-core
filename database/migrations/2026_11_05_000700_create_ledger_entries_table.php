<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete(); // index serves "history of wallet X"
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete(); // "all money moves for order X"
            $table->string('type', 16);   // DEPOSIT / RESERVE / RELEASE / TRADE_BUY / TRADE_SELL
            $table->string('bucket', 10); // AVAILABLE / RESERVED
            $table->decimal('amount', 20, 8);        // signed: + into the bucket, - out of it
            $table->decimal('balance_after', 20, 8); // that bucket's balance right after this entry
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_amount_not_zero CHECK (amount <> 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_balance_after_non_negative CHECK (balance_after >= 0)');

        // Append-only, enforced by the database: any UPDATE or DELETE is refused.
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ledger_entries_no_update BEFORE UPDATE ON ledger_entries
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ledger_entries_no_delete BEFORE DELETE ON ledger_entries
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only'
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
        }
        Schema::dropIfExists('ledger_entries');
    }
};
