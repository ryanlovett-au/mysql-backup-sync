<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EstimatingBackup;
use Tests\TestCase;

class EstimateTest extends TestCase
{
    protected EstimatingBackup $backup;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.src', ['driver' => 'sqlite', 'database' => ':memory:']);
        DB::purge('src');

        Schema::connection('src')->create('orders', function ($table) {
            $table->integer('id')->primary();
        });
        Schema::connection('src')->create('uuids', function ($table) {
            $table->string('id')->primary();
        });

        // ids 1..300 standing in for a table of 300 million
        DB::connection('src')->table('orders')->insert(
            array_map(fn ($i) => ['id' => $i], range(1, 300))
        );
        DB::connection('src')->table('uuids')->insert([['id' => 'a-1'], ['id' => 'b-2']]);

        $this->backup = new EstimatingBackup;
        $this->backup->remote_db = 'src';
        $this->backup->estimate = 300_000_000;
    }

    protected function subject(string $table): EstimatingBackup
    {
        $this->backup->table = (object) ['table_name' => $table];

        return $this->backup;
    }

    public function test_a_fresh_sync_expects_the_whole_table(): void
    {
        $this->assertSame(300_000_000, $this->subject('orders')->estimateAbove('id', 0));
    }

    public function test_a_resumed_sync_expects_what_is_left(): void
    {
        // half way through the key range, so roughly half the rows remain
        $estimate = $this->subject('orders')->estimateAbove('id', 150);

        $this->assertGreaterThan(145_000_000, $estimate);
        $this->assertLessThan(155_000_000, $estimate);
    }

    public function test_a_finished_sync_expects_nothing(): void
    {
        $this->assertSame(0, $this->subject('orders')->estimateAbove('id', 300));
    }

    public function test_a_cursor_past_the_end_never_goes_negative(): void
    {
        $this->assertSame(0, $this->subject('orders')->estimateAbove('id', 400));
    }

    public function test_a_key_it_cannot_do_arithmetic_on_gives_up(): void
    {
        $this->assertNull($this->subject('uuids')->estimateAbove('id', 'a-1'));
    }

    public function test_it_gives_up_when_the_server_offers_no_estimate(): void
    {
        $this->backup->estimate = null;

        $this->assertNull($this->subject('orders')->estimateAbove('id', 0));
    }
}
