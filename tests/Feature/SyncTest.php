<?php

namespace Tests\Feature;

use App\Database\Backup;
use App\Database\Interrupt;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\BuildsBackups;
use Tests\Support\StubConnection;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use BuildsBackups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpConnections(
            fn ($schema) => $schema->create('orders', function ($table) {
                $table->integer('id')->primary();
                $table->string('ref');
                $table->dateTime('updated_at')->nullable();
            }),
            'CREATE TABLE orders (id INTEGER PRIMARY KEY, ref TEXT, updated_at TEXT)'
        );

        $this->config(['select_count' => '100', 'update_count' => '40']);
    }

    protected function tearDown(): void
    {
        $this->releaseAppOutput();
        Interrupt::release();
        StubConnection::reset();

        parent::tearDown();
    }

    public function test_it_copies_every_row(): void
    {
        $this->sourceRows('orders', 500);

        $this->makeBackup('orders', [], ['last_id' => 0])->update();

        $this->assertSame(500, StubConnection::rows_written());
    }

    public function test_it_backs_off_when_the_server_refuses_a_write(): void
    {
        $this->sourceRows('orders', 500);

        // three columns, so forty rows is 120 bindings - refuse anything over sixty
        StubConnection::$refuse_writes_over = 60;

        $backup = $this->makeBackup('orders', [], ['last_id' => 0]);
        $backup->update();

        $refusals = count(array_filter(StubConnection::$log, fn ($e) => $e === 'write refused'));

        $this->assertSame(1, $refusals, 'it should discover the limit once, not on every batch');
        $this->assertSame(500, StubConnection::rows_written(), 'every row still lands');
        $this->assertSame(20, $backup->table->fresh()->write_size, 'the smaller size is remembered');
    }

    public function test_it_does_not_swallow_an_error_that_is_not_about_size(): void
    {
        $this->sourceRows('orders', 100);

        StubConnection::$refuse_writes_over = 60;
        StubConnection::$refuse_writes_with = 1142;

        $this->expectException(\PDOException::class);

        $this->makeBackup('orders', [], ['last_id' => 0])->update();
    }

    public function test_ctrl_c_stops_on_a_batch_boundary_with_its_state_saved(): void
    {
        $this->sourceRows('orders', 500);

        Interrupt::listen();

        // fire the interrupt once a hundred rows have landed, exactly as a ctrl-c would
        StubConnection::$after_write = function () {
            if (StubConnection::rows_written() >= 100 && ! Interrupt::requested()) {
                posix_kill(posix_getpid(), SIGINT);
            }
        };

        $backup = $this->makeBackup('orders', [], ['last_id' => 0]);
        $backup->update();

        $this->assertTrue(Interrupt::requested());
        $this->assertSame(100, StubConnection::rows_written(), 'it stops on a whole batch');
        $this->assertSame(100, (int) $backup->state->fresh()->last_id, 'the cursor matches what was written');
    }

    /**
     * Run something with the prompt output captured, and hand back what it drew. Prompts writes to
     * its own stream rather than to stdout, so this swaps that stream rather than buffering output.
     */
    protected function outputOf(callable $work): string
    {
        $buffer = new BufferedOutput;

        Prompt::setOutput($buffer);

        try {
            $work();
        } finally {
            Prompt::setOutput(new NullOutput);
        }

        return $buffer->fetch();
    }

    public function test_a_table_with_nothing_to_do_still_reports_itself(): void
    {
        $this->sourceRows('orders', 10);

        // a watermark later than every row, so there is no work to be found
        $backup = $this->makeBackup('orders', [], ['last_updated_at' => '2030-01-01 00:00:00', 'last_id' => 10]);

        $shown = $this->outputOf(fn () => $backup->update());

        $this->assertSame(0, StubConnection::rows_written());
        $this->assertStringContainsString('orders', $shown, 'the table should still appear in the run');
        $this->assertStringContainsString('nothing to update', $shown);
    }

    public function test_a_missing_index_is_collected_but_not_said_between_every_bar(): void
    {
        Backup::$missing_timestamp_indexes = [];

        $this->sourceRows('orders', 40);

        $backup = $this->makeBackup('orders', [], ['last_updated_at' => '2020-01-01 00:00:00', 'last_id' => 1]);

        $shown = $this->outputOf(fn () => $backup->update());

        $this->assertContains('testdb.orders', Backup::$missing_timestamp_indexes, 'still collected');
        $this->assertStringNotContainsString('No index on updated_at', $shown, 'but not said inline');
    }

    public function test_a_table_set_to_always_resync_is_not_nagged_about_an_index(): void
    {
        Backup::$missing_timestamp_indexes = [];

        $this->sourceRows('orders', 40);

        $this->makeBackup('orders', ['always_resync' => 1])->update();

        $this->assertSame([], Backup::$missing_timestamp_indexes, 'it does not track by updated_at');
    }

    public function test_a_table_set_to_use_its_primary_key_is_not_nagged_about_an_index(): void
    {
        Backup::$missing_timestamp_indexes = [];

        $this->sourceRows('orders', 40);

        $this->makeBackup('orders', ['always_primary_key' => 1], ['last_id' => 0])->update();

        $this->assertSame([], Backup::$missing_timestamp_indexes, 'it does not track by updated_at');
    }

    public function test_a_resync_truncates(): void
    {
        $this->sourceRows('orders', 40);

        $this->makeBackup('orders', ['always_resync' => 1])->resync();

        $this->assertContains('truncate', StubConnection::$log);
        $this->assertNotContains('delete', StubConnection::$log);
    }

    public function test_a_resync_falls_back_to_deleting_when_truncate_is_refused(): void
    {
        $this->sourceRows('orders', 40);

        StubConnection::$refuse_truncate = true;

        $this->makeBackup('orders', ['always_resync' => 1])->resync();

        $this->assertContains('truncate refused', StubConnection::$log);
        $this->assertContains('delete', StubConnection::$log, 'it still clears the table');
        $this->assertSame(40, StubConnection::rows_written());
    }
}
