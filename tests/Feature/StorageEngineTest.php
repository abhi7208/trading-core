<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorageEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Transactions and row locks only exist in InnoDB. If a server defaults to MyISAM,
     * every locking guarantee in this app silently disappears, so fail loudly instead.
     */
    #[Test]
    public function every_table_uses_innodb(): void
    {
        $tables = DB::table('information_schema.tables')
            ->selectRaw('TABLE_NAME AS name, ENGINE AS engine')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_type', 'BASE TABLE')
            ->pluck('engine', 'name');

        $this->assertNotEmpty($tables);
        foreach ($tables as $table => $engine) {
            $this->assertSame('InnoDB', $engine, "Table {$table} uses {$engine}, not InnoDB.");
        }
    }
}
