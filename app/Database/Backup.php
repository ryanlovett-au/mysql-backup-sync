<?php

namespace App\Database;

use App\Models\Config;
use App\Models\State;
use App\Models\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class Backup
{
    public string $local_db = '';

    public string $remote_db = '';

    public string $database_name = '';

    public $table = null;

    public $state = null;

    // Where a table has never been synced, the updated_at we start from
    public const EPOCH = '1900-01-01 00:00:01';

    // Below this many rows an exact count is quick, above it we take the estimate and show a ~
    public const ESTIMATE_ABOVE = 10000;

    // MySQL will not take more than this many placeholders in one prepared statement
    public const MAX_PLACEHOLDERS = 65535;

    // Errors that mean the statement was too big, rather than anything being wrong with it
    public const TOO_LARGE = [1390, 1153];

    // Rows cost several times their stored size once PHP holds them as objects. Measured at four to
    // six times for typical mixed columns, so this errs high - the failure it guards is a hard one.
    public const MEMORY_OVERHEAD = 6;

    protected $status = null;

    protected bool $status_loaded = false;

    // Say nothing about time remaining for the first twenty seconds, then wait for a few passes so
    // the rate has settled - but never stay quiet past ETA_BY, however slow a pass is
    public const ETA_AFTER = 20;

    public const ETA_SAMPLES = 3;

    public const ETA_BY = 30;

    // How many passes the rate is averaged over. Enough to ride out a slow one, few enough that a
    // real change in throughput shows up while it is still worth knowing about.
    public const ETA_WINDOW = 5;

    protected array $samples = [];

    protected ?float $marked = null;

    // Tables tracking by updated_at with no index to support it, collected across the whole run
    public static array $missing_timestamp_indexes = [];

    public function __construct($database, $local, $table)
    {
        $this->local_db = $local;
        $this->remote_db = 'host_'.$database->host_id;
        $this->database_name = $database->database_name;

        $this->table = Table::where('database_id', $database->id)
            ->where('table_name', $table)
            ->first();

        if (! $this->table) {
            $create = new Table;
            $create->database_id = $database->id;
            $create->table_name = $table;
            $create->save();
            $this->table = $create;
        }

        if ($state = State::where('host_id', $database->host_id)
            ->where('database_id', $database->id)
            ->where('table_name', $table)
            ->first()) {
            $this->state = $state;
        } else {
            $this->state = new State;
            $this->state->host_id = $database->host_id;
            $this->state->database_id = $database->id;
            $this->state->table_name = $table;
        }
    }

    public function action($cli = false)
    {
        if (! $this->table) {
            alert('Table config not found, consider re-sycning tables.');

            if (! $cli) {
                pause();
            }

            return;
        }

        if (! $this->table->is_active) {
            return;
        }

        // A position to carry on from means an incremental run. Tables with no single column primary
        // key never get one, so they fall through to a full resync - which is what they need, as an
        // upsert with no key to match on would only append another copy of the table.
        if ($this->state->last_id && ! $this->table->always_resync) {
            $this->update();

            return;
        }

        $this->resync();
    }

    public function has_timestamps(): bool
    {
        return DBSchema::connection($this->remote_db)->hasColumn($this->table->table_name, 'updated_at');
    }

    public function has_timestamp_index(): bool
    {
        $indexes = DBSchema::connection($this->remote_db)->getIndexes($this->table->table_name);

        // Only a leading updated_at can serve the range scan, an index like (client_id, updated_at) cannot
        return collect($indexes)->contains(fn ($index) => ($index['columns'][0] ?? null) === 'updated_at');
    }

    protected function check_timestamp_index(): void
    {
        if ($this->has_timestamp_index()) {
            return;
        }

        $table = $this->database_name.'.'.$this->table->table_name;

        // Collected for the end of the run rather than said here. On a database of any size this
        // fires for table after table, and a warning between every progress bar is not read.
        if (! in_array($table, self::$missing_timestamp_indexes)) {
            self::$missing_timestamp_indexes[] = $table;
        }
    }

    /**
     * What the source server says about this table. Only ever asked once, and only a guess - InnoDB
     * keeps these figures as statistics rather than counting anything.
     */
    protected function table_status(): ?object
    {
        if (! $this->status_loaded) {
            $this->status_loaded = true;

            try {
                $this->status = DB::connection($this->remote_db)
                    ->selectOne('SHOW TABLE STATUS WHERE Name = ?', [$this->table->table_name]);
            } catch (\Throwable $e) {
                // Not every server will hand this over, counting still works
                $this->status = null;
            }
        }

        return $this->status;
    }

    protected function estimated_rows(): ?int
    {
        $status = $this->table_status();

        return isset($status->Rows) ? (int) $status->Rows : null;
    }

    protected function average_row_length(): ?int
    {
        $status = $this->table_status();

        return isset($status->Avg_row_length) ? (int) $status->Avg_row_length : null;
    }

    /**
     * How many rows to pull from the source at once. Nothing caps this the way placeholders cap a
     * write, but the whole batch is held in memory as objects, which costs several times what the
     * rows measure on disk. Going over does not raise an error we can catch - it kills the run - so
     * this stays well inside what we have.
     */
    protected function select_size(): int
    {
        $configured = max(1, (int) Config::get('select_count'));

        $row_length = $this->average_row_length();

        if (is_null($row_length) || $row_length < 1) {
            return $configured;
        }

        return max(1, min($configured, intdiv(Memory::budget(), $row_length * self::MEMORY_OVERHEAD)));
    }

    /**
     * Estimate how much of the table is still to come, without counting it. MIN and MAX on an indexed
     * column are endpoint lookups the optimiser answers from the index, so this costs nothing even on
     * a table of hundreds of millions of rows. Assumes the key is reasonably evenly spread.
     */
    protected function estimated_rows_above(string $column, $value): ?int
    {
        $total = $this->estimated_rows();

        if (is_null($total)) {
            return null;
        }

        $grammar = DB::connection($this->remote_db)->getQueryGrammar();

        try {
            $bounds = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->selectRaw('MIN('.$grammar->wrap($column).') as low, MAX('.$grammar->wrap($column).') as high')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }

        // Anything we cannot do arithmetic on (uuids, strings) is not something we can apportion
        if (! isset($bounds->low, $bounds->high) || ! is_numeric($bounds->low) || ! is_numeric($bounds->high)) {
            return null;
        }

        $span = $bounds->high - $bounds->low;

        if ($span <= 0) {
            return null;
        }

        // The cursor can sit past the top of the table if rows have since been removed at the source
        $remaining = max(0, $bounds->high - max((float) $value, (float) $bounds->low));

        return (int) round($total * ($remaining / $span));
    }

    public static function report_missing_indexes(): void
    {
        if (count(self::$missing_timestamp_indexes) === 0) {
            return;
        }

        note('');
        warning('These tables track changes by updated_at but have no index on that column:');

        foreach (self::$missing_timestamp_indexes as $table) {
            warning(' - '.$table);
        }

        note('');
        note('Every batch on these tables scans and sorts the entire table, which is slow and is the');
        note('usual cause of error 2006. Adding an index on the source server will fix it:');
        note('');
        note('   ALTER TABLE <table> ADD INDEX updated_at (updated_at);');
        note('');
    }

    /**
     * Record how long a pass took and how much it moved, keeping only the most recent few. A rate
     * over the whole run stops meaning anything after a few hours - it cannot show a slowdown, or a
     * recovery, until they have outweighed everything that came before them.
     */
    protected function sample(int $rows, float $started): void
    {
        $now = microtime(true);

        $this->samples[] = [$rows, $now - ($this->marked ?? $started)];

        $this->marked = $now;

        if (count($this->samples) > self::ETA_WINDOW) {
            array_shift($this->samples);
        }
    }

    /**
     * How much longer this table looks like taking, at the rate of the last few passes. Holds off
     * until there is enough to go on - the first pass carries the connection and query setup with
     * it, so a rate taken from that alone reads far worse than the table actually runs.
     */
    protected function eta(float $started, int $done, int $total): ?string
    {
        $elapsed = microtime(true) - $started;

        if ($done < 1 || $done >= $total) {
            return null;
        }

        if ($elapsed < self::ETA_AFTER) {
            return null;
        }

        if (count($this->samples) < self::ETA_SAMPLES && $elapsed < self::ETA_BY) {
            return null;
        }

        $rows = array_sum(array_column($this->samples, 0));
        $seconds = array_sum(array_column($this->samples, 1));

        if ($rows < 1 || $seconds <= 0) {
            return null;
        }

        return 'About '.self::duration((int) round(($total - $done) * ($seconds / $rows))).' remaining';
    }

    protected static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($hours < 24) {
            return $hours.'h '.$minutes.'m';
        }

        return floor($hours / 24).'d '.($hours % 24).'h';
    }

    /**
     * How many rows to put in one write. Every column of every row is a placeholder and MySQL will
     * not take more than 65535 of them in a statement, so the real ceiling belongs to the table, not
     * to a global setting that has to be low enough for the widest table in the database.
     */
    protected function write_size(array $columns): int
    {
        $limits = [
            max(1, (int) Config::get('update_count')),
            intdiv(self::MAX_PLACEHOLDERS, max(1, count($columns))),
        ];

        // A size this table has already been forced down to, so we do not fail our way back to it
        if ($this->table->write_size > 0) {
            $limits[] = (int) $this->table->write_size;
        }

        return max(1, min($limits));
    }

    public function update(): void
    {
        // Determine how to manage state
        $timestamps = $this->has_timestamps();
        $key_columns = $this->get_primary_key_columns();

        // The first key column identifies the table well enough to order and hold state by, but only
        // a single column key is unique on its own, which is what seeking from a cursor requires
        $primary_key = $key_columns[0] ?? null;
        $unique_key = count($key_columns) === 1 ? $key_columns[0] : null;

        // Determine which query type to use
        if ($this->table->always_resync || $primary_key == null) {
            $strategy = 'resync';
        } elseif ($timestamps && ! $this->table->always_primary_key) {
            $strategy = 'timestamps';
        } elseif ($unique_key !== null) {
            $strategy = 'primary_key';
        } else {
            // Asked to track by primary key, but a composite key gives us nothing safe to seek from -
            // seeking on part of it silently drops every row that shares that leading value
            warning('Table '.$this->table->table_name.' has a composite primary key and no updated_at to track, resyncing it in full instead.');

            $strategy = 'resync';
        }

        // Build the source query for the chosen strategy. The keyset strategies return a closure
        // because they are re-built for every batch, reading the cursor out of state as it advances.
        if ($strategy === 'resync') {
            $query = function () use ($key_columns) {
                $query = DB::connection($this->remote_db)
                    ->table($this->table->table_name);

                // Order by the whole primary key, or by the first column if there is no primary key.
                // Paging by offset needs a total order or rows can shuffle between batches.
                if (! empty($key_columns)) {
                    foreach ($key_columns as $column) {
                        $query->orderBy($column);
                    }
                } else {
                    $query->orderBy(
                        DBSchema::connection($this->remote_db)
                            ->getColumnListing($this->table->table_name)[0],
                        'asc'
                    );
                }

                return $query;
            };

            $counter = fn () => DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->count();
        } elseif ($strategy === 'timestamps') {
            if (empty($this->state->last_updated_at)) {
                $this->state->last_updated_at = self::EPOCH;
            }

            // This strategy leans on an index over updated_at, warn if there isn't one
            $this->check_timestamp_index();

            // Seek straight to the cursor rather than paging past everything before it. On InnoDB an
            // index on updated_at already carries the primary key, so (updated_at, id) is index order.
            $query = fn () => DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where('updated_at', '>=', $this->state->last_updated_at)
                ->when(! is_null($unique_key) && ! is_null($this->state->last_id), fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('updated_at', '>', $this->state->last_updated_at)
                        ->orWhere(fn ($query) => $query
                            ->where('updated_at', '=', $this->state->last_updated_at)
                            ->where($unique_key, '>', $this->state->last_id)
                        )
                ))
                ->orderBy('updated_at', 'asc')
                ->when(! is_null($unique_key), fn ($query) => $query->orderBy($unique_key, 'asc'));

            $counter = fn () => $query()->count();
        } else {
            if (empty($this->state->last_id)) {
                $this->state->last_id = 0;
            }

            $query = fn () => DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where($unique_key, '>', $this->state->last_id)
                ->orderBy($unique_key, 'asc');

            $counter = fn () => $query()->count();
        }

        // Work out the progress total. Counting is exact but on a very large table it is a scan we do
        // not otherwise need, and on a resumed first sync we would pay it on every single run. Where
        // the server's estimate says there is a lot still to come, take that instead and flag it.
        $estimate = match ($strategy) {
            'resync' => $this->estimated_rows(),
            'timestamps' => $this->state->last_updated_at === self::EPOCH && is_null($this->state->last_id)
                ? $this->estimated_rows()
                : null,
            default => $this->estimated_rows_above($unique_key, $this->state->last_id),
        };

        // Only ever trust an estimate that is confidently large. Below the threshold a count is quick
        // anyway, and this is what keeps a bogus estimate of 0 from skipping the table entirely.
        $estimated = ! is_null($estimate) && $estimate >= self::ESTIMATE_ABOVE;

        // Counting a large table is slow enough to look like a hang, so say what we are waiting on.
        // The spinner erases itself, leaving the progress bar in its place.
        $count = $estimated ? $estimate : spin(
            message: 'Counting rows in '.$this->table->table_name,
            callback: $counter
        );

        // Every table gets a bar, including one with nothing to do, so a run visibly walks the whole
        // list rather than going quiet on the tables it had no work for. Prompts will not build a bar
        // of no steps, so an empty one is a single step already taken, and says so.
        $progress = progress(
            label: ($this->table->always_resync ? 'Resyncing' : 'Updating').' '.$this->table->table_name
                .($estimated ? ' ~' : '')
                .($count > 0 ? '' : ' (nothing to update)'),
            steps: max(1, $count)
        );

        $progress->start();

        // The progress bar just took ctrl-c off us, take it back
        Interrupt::listen();

        // Are we going??
        if ($count > 0) {
            $started = microtime(true);

            // Rate samples run from here, so the first one covers the first select as well
            $this->samples = [];
            $this->marked = $started;

            $select_count = $this->select_size();

            // The destination structure cannot change mid-run, so only look the columns up once
            $columns = DBSchema::connection($this->local_db)
                ->getColumnListing($this->table->table_name);

            $write_size = $this->write_size($columns);

            $write = function ($rows) use ($progress, $primary_key, $timestamps, $columns, $started, $count, &$write_size) {

                $queue = $rows->all();
                $written = 0;

                while (count($queue) > 0) {
                    // Cast rows to arrays
                    $fewerows = array_map(function ($row) {
                        return (array) $row;
                    }, array_slice($queue, 0, $write_size));

                    // Insert
                    try {
                        DB::connection($this->local_db)
                            ->table($this->table->table_name)
                            ->upsert($fewerows, [], $columns);
                    } catch (\Throwable $e) {
                        // The server can refuse a statement on size alone, going on the bytes in it
                        // rather than the placeholder count we sized for. Halve and hold the smaller
                        // size, for the rest of this table and for the next run.
                        if (! in_array((int) Error::code($e), self::TOO_LARGE, true) || count($fewerows) < 2) {
                            throw $e;
                        }

                        $write_size = max(1, intdiv($write_size, 2));

                        $this->table->write_size = $write_size;
                        $this->table->save();

                        continue;
                    }

                    array_splice($queue, 0, count($fewerows));

                    // Update state as we go - only use primary key as that is how we are getting source rows
                    $last = end($fewerows);
                    $this->state->last_updated_at = $timestamps ? $last['updated_at'] : null;
                    $this->state->last_id = $last[$primary_key] ?? null;
                    $this->state->save();

                    $written += count($fewerows);

                    $progress->advance(count($fewerows));

                    // Stop on a whole batch, with the state for it already written
                    if (Interrupt::requested()) {
                        break;
                    }
                }

                // One sample per pass, so it spans a select and the writes that emptied it. Sampling
                // the writes alone would read fast, then jump every time a select added time with no
                // rows against it - the rate is only honest over a whole cycle.
                $this->sample($written, $started);

                if ($eta = $this->eta($started, $progress->progress, $count)) {
                    $progress->hint($eta);
                    $progress->render();
                }

                if (Interrupt::requested()) {
                    return false;
                }
            };

            // Get the data. Without a unique key there is no cursor to seek from, so those tables keep
            // paging by offset - building the query once so the batches page a fixed result set.
            if ($strategy === 'resync' || is_null($unique_key)) {
                $query()->chunk($select_count, $write);
            } else {
                $this->chunk_by_cursor($query, $select_count, $write);
            }
        } else {
            $progress->advance();
        }

        $progress->finish();
        echo "\n";
    }

    /**
     * Page through the source using the state cursor rather than an offset. The query closure reads
     * the cursor and the callback advances it, so each batch picks up exactly where the last stopped.
     */
    protected function chunk_by_cursor(callable $query, int $size, callable $callback): void
    {
        do {
            $rows = $query()->limit($size)->get();

            if ($rows->isEmpty()) {
                break;
            }

            $cursor = [$this->state->last_updated_at, $this->state->last_id];

            $callback($rows);

            if (Interrupt::requested()) {
                break;
            }

            // The cursor has to move, otherwise we would ask the source for the same rows forever
            if ($cursor === [$this->state->last_updated_at, $this->state->last_id]) {
                alert('Error: sync cursor for '.$this->table->table_name.' did not advance, skipping the rest of this table.');
                break;
            }
        } while ($rows->count() === $size);
    }

    public function resync(): void
    {
        // Truncate. It drops and recreates rather than working through the table a row at a time,
        // which is the difference between an instant clear and a very long one. The server refuses
        // it on a table another table's foreign key points at, and it wants DROP rather than DELETE
        // privilege, so fall back to the slow way where it will not run at all.
        try {
            DB::connection($this->local_db)->table($this->table->table_name)->truncate();
        } catch (\Throwable $e) {
            warning('Could not truncate '.$this->table->table_name.' ('.Error::describe($e).'), clearing it a row at a time instead.');

            DB::connection($this->local_db)->table($this->table->table_name)->delete();
        }

        // Sync
        $this->update();
    }

    /**
     * Every column of the primary key, in key order. A composite key returns more than one, and a
     * table without a primary key returns none.
     */
    protected function get_primary_key_columns(): array
    {
        $indexes = DBSchema::connection($this->local_db)->getIndexes($this->table->table_name);

        $primary = collect($indexes)->firstWhere('primary', true);

        return $primary['columns'] ?? [];
    }

    protected function get_last_id(): ?int
    {
        return DB::connection($this->local_db)->table($this->table->table_name)->orderBy('id', 'desc')->first()?->id;
    }

    protected function get_last_update_at(): ?string
    {
        if ($this->has_timestamps()) {
            return DB::connection($this->local_db)->table($this->table->table_name)->orderBy('updated_at', 'desc')->first()?->updated_at;
        }

        return null;
    }
}
