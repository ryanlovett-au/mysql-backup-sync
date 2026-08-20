<?php

namespace Tests\Feature;

use App\Database\Backup;
use App\Models\Table as TableModel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use ReflectionClass;
use Tests\TestCase;

/**
 * The handful of behaviours that only a real MySQL can show us - the upsert compiling to ON
 * DUPLICATE KEY UPDATE, SHOW TABLE STATUS, and the placeholder ceiling the write size is derived
 * from. Everything here is confined to one database of its own, which it creates and drops.
 */
class MysqlTest extends TestCase
{
    protected const DATABASE = 'mysql_backup_sync_phpunit';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.mysql_test', [
            'driver' => 'mysql',
            'host' => env('MYSQL_TEST_HOST', '127.0.0.1'),
            'port' => env('MYSQL_TEST_PORT', '3306'),
            'database' => null,
            'username' => env('MYSQL_TEST_USERNAME', 'root'),
            'password' => env('MYSQL_TEST_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'prefix' => '',
            'prefix_indexes' => true,
            'engine' => null,
            'options' => [],
        ]);

        DB::purge('mysql_test');

        try {
            DB::connection('mysql_test')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('no MySQL to test against: '.$e->getMessage());
        }

        DB::connection('mysql_test')->statement('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'`');

        Config::set('database.connections.mysql_test.database', self::DATABASE);
        DB::purge('mysql_test');
    }

    protected function tearDown(): void
    {
        if (Config::get('database.connections.mysql_test')) {
            try {
                Config::set('database.connections.mysql_test.database', null);
                DB::purge('mysql_test');
                DB::connection('mysql_test')->statement('DROP DATABASE IF EXISTS `'.self::DATABASE.'`');
            } catch (\Throwable $e) {
                // nothing to clean up
            }
        }

        parent::tearDown();
    }

    protected function wideTable(int $columns): array
    {
        $names = array_map(fn ($i) => 'col'.$i, range(1, $columns));

        $definition = implode(', ', array_map(fn ($c) => '`'.$c.'` VARCHAR(20) NULL', $names));

        DB::connection('mysql_test')->statement(
            'CREATE TABLE `wide` (`id` INT NOT NULL PRIMARY KEY, '.$definition.') ENGINE=InnoDB'
        );

        return array_merge(['id'], $names);
    }

    public function test_upsert_with_no_named_key_updates_rather_than_duplicating(): void
    {
        DB::connection('mysql_test')->statement(
            'CREATE TABLE `orders` (`id` INT NOT NULL PRIMARY KEY, `ref` VARCHAR(20)) ENGINE=InnoDB'
        );

        $columns = ['id', 'ref'];
        $rows = [['id' => 1, 'ref' => 'first'], ['id' => 2, 'ref' => 'second']];

        // this is how the application writes - an empty uniqueBy, which only MySQL accepts
        DB::connection('mysql_test')->table('orders')->upsert($rows, [], $columns);
        DB::connection('mysql_test')->table('orders')->upsert(
            [['id' => 1, 'ref' => 'changed'], ['id' => 3, 'ref' => 'third']], [], $columns
        );

        $this->assertSame(3, DB::connection('mysql_test')->table('orders')->count(), 'the repeat must not duplicate');
        $this->assertSame('changed', DB::connection('mysql_test')->table('orders')->where('id', 1)->value('ref'));
    }

    public function test_show_table_status_answers_a_bound_placeholder(): void
    {
        DB::connection('mysql_test')->statement(
            'CREATE TABLE `orders` (`id` INT NOT NULL PRIMARY KEY, `ref` VARCHAR(20)) ENGINE=InnoDB'
        );
        DB::connection('mysql_test')->table('orders')->insert([['id' => 1, 'ref' => 'a'], ['id' => 2, 'ref' => 'b']]);

        $status = DB::connection('mysql_test')->selectOne('SHOW TABLE STATUS WHERE Name = ?', ['orders']);

        $this->assertNotNull($status, 'the placeholder must bind, this is what the row estimate relies on');
        $this->assertObjectHasProperty('Rows', $status);
        $this->assertObjectHasProperty('Avg_row_length', $status);
    }

    public function test_the_server_refuses_more_placeholders_than_the_ceiling(): void
    {
        $columns = $this->wideTable(70);

        // 70 columns plus the key, so a thousand rows is over seventy thousand placeholders
        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = array_fill_keys($columns, 'x') + ['id' => $i];
        }

        try {
            DB::connection('mysql_test')->table('wide')->upsert($rows, [], $columns);
            $this->fail('the server was expected to refuse this many placeholders');
        } catch (PDOException|\Illuminate\Database\QueryException $e) {
            $this->assertSame(1390, (int) \App\Database\Error::code($e));
        }
    }

    public function test_the_derived_write_size_stays_under_the_ceiling(): void
    {
        $columns = $this->wideTable(70);

        $this->config(['update_count' => '2500']);

        $row = new TableModel;
        $row->database_id = 1;
        $row->table_name = 'wide';
        $row->save();

        $backup = (new ReflectionClass(Backup::class))->newInstanceWithoutConstructor();
        $backup->table = $row;

        $method = (new ReflectionClass(Backup::class))->getMethod('write_size');
        $method->setAccessible(true);

        $size = $method->invoke($backup, $columns);

        $this->assertLessThanOrEqual(
            Backup::MAX_PLACEHOLDERS,
            $size * count($columns),
            'the derived size must keep the statement inside what the server just refused'
        );
        $this->assertGreaterThan(0, $size);
    }

    protected function config(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('config')->updateOrInsert(['key' => $key], ['field_type' => 'text', 'value' => $value]);
        }
    }
}
