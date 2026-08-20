<?php

namespace Tests\Feature;

use App\Database\Backup;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class StrategyTest extends TestCase
{
    protected function keyColumnsFor(callable $definition): array
    {
        Config::set('database.connections.dest', ['driver' => 'sqlite', 'database' => ':memory:']);
        \Illuminate\Support\Facades\DB::purge('dest');

        Schema::connection('dest')->create('subject', $definition);

        $backup = (new ReflectionClass(Backup::class))->newInstanceWithoutConstructor();
        $backup->local_db = 'dest';
        $backup->table = (object) ['table_name' => 'subject'];

        $method = (new ReflectionClass(Backup::class))->getMethod('get_primary_key_columns');
        $method->setAccessible(true);

        return $method->invoke($backup);
    }

    public function test_a_single_column_key_is_usable_as_a_cursor(): void
    {
        $columns = $this->keyColumnsFor(function ($table) {
            $table->integer('id')->primary();
            $table->string('ref');
        });

        $this->assertSame(['id'], $columns);
        $this->assertCount(1, $columns, 'a single column key can be seeked from');
    }

    public function test_a_composite_key_reports_every_column(): void
    {
        $columns = $this->keyColumnsFor(function ($table) {
            $table->integer('user_id');
            $table->integer('role_id');
            $table->primary(['user_id', 'role_id']);
        });

        // The bug was returning only user_id and seeking on it, which drops every row that
        // shares a user_id with the last row of a batch.
        $this->assertSame(['user_id', 'role_id'], $columns);
        $this->assertGreaterThan(1, count($columns), 'a composite key is not unique on its own');
    }

    public function test_a_table_with_no_key_reports_none(): void
    {
        $columns = $this->keyColumnsFor(function ($table) {
            $table->string('blob');
        });

        $this->assertSame([], $columns);
    }
}
