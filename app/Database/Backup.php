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
use function Laravel\Prompts\warning;

class Backup
{
    public string $local_db = '';

    public string $remote_db = '';

    public string $database_name = '';

    public $table = null;

    public $state = null;

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

        if (($this->state->last_id || $this->state->last_updated_id) && ! $this->table->always_resync) {
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

        if (! in_array($table, self::$missing_timestamp_indexes)) {
            self::$missing_timestamp_indexes[] = $table;
        }

        warning('No index on updated_at for '.$this->table->table_name.', every batch must scan and sort the whole table.');
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

    public function update(): void
    {
        // Determine how to manage state
        $timestamps = $this->has_timestamps();
        $primary_key = $this->get_primary_key();

        // Determine which query type to use
        if ($this->table->always_resync || $primary_key == null) {
            $strategy = 'resync';
        } elseif ($timestamps && ! $this->table->always_primary_key) {
            $strategy = 'timestamps';
        } else {
            $strategy = 'primary_key';
        }

        // Build the source query for the chosen strategy. The keyset strategies return a closure
        // because they are re-built for every batch, reading the cursor out of state as it advances.
        if ($strategy === 'resync') {
            $query = function () use ($primary_key) {
                $query = DB::connection($this->remote_db)
                    ->table($this->table->table_name);

                // Order by the primary key or by the first column if no primary key
                if (! empty($primary_key)) {
                    $query->orderBy($primary_key);
                } else {
                    $query->orderBy(
                        DBSchema::connection($this->remote_db)
                            ->getColumnListing($this->table->table_name)[0],
                        'asc'
                    );
                }

                return $query;
            };

            $count = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->count();
        } elseif ($strategy === 'timestamps') {
            if (empty($this->state->last_updated_at)) {
                $this->state->last_updated_at = '1900-01-01 00:00:01';
            }

            // This strategy leans on an index over updated_at, warn if there isn't one
            $this->check_timestamp_index();

            // Seek straight to the cursor rather than paging past everything before it. On InnoDB an
            // index on updated_at already carries the primary key, so (updated_at, id) is index order.
            $query = fn () => DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where('updated_at', '>=', $this->state->last_updated_at)
                ->when(! is_null($this->state->last_id), fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('updated_at', '>', $this->state->last_updated_at)
                        ->orWhere(fn ($query) => $query
                            ->where('updated_at', '=', $this->state->last_updated_at)
                            ->where($primary_key, '>', $this->state->last_id)
                        )
                ))
                ->orderBy('updated_at', 'asc')
                ->orderBy($primary_key, 'asc');

            $count = $query()->count();
        } else {
            if (empty($this->state->last_id)) {
                $this->state->last_id = 0;
            }

            $query = fn () => DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where($primary_key, '>', $this->state->last_id)
                ->orderBy($primary_key, 'asc');

            $count = $query()->count();
        }

        // Are we going??
        if ($count > 0) {
            $progress = progress(label: ($this->table->always_resync ? 'Resyncing' : 'Updating').' '.$this->table->table_name, steps: $count);
            $progress->start();

            $select_count = (int) Config::get('select_count');
            $update_count = (int) Config::get('update_count');

            // The destination structure cannot change mid-run, so only look the columns up once
            $columns = DBSchema::connection($this->local_db)
                ->getColumnListing($this->table->table_name);

            $write = function ($rows) use ($progress, $primary_key, $timestamps, $update_count, $columns) {

                foreach ($rows->chunk($update_count) as $fewerows) {
                    // Cast rows to arrays
                    $fewerows = array_map(function ($row) {
                        return (array) $row;
                    }, $fewerows->toArray());

                    // Insert
                    DB::connection($this->local_db)
                        ->table($this->table->table_name)
                        ->upsert($fewerows, [], $columns);

                    // Update state as we go - only use primary key as that is how we are getting source rows
                    $last = end($fewerows);
                    $this->state->last_updated_at = $timestamps ? $last['updated_at'] : null;
                    $this->state->last_id = $last[$primary_key] ?? null;
                    $this->state->save();

                    $progress->advance(count($fewerows));
                }
            };

            // Get the data
            if ($strategy === 'resync') {
                $query()->chunk($select_count, $write);
            } else {
                $this->chunk_by_cursor($query, $select_count, $write);
            }

            $progress->finish();
            echo "\n";
        }
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

            // The cursor has to move, otherwise we would ask the source for the same rows forever
            if ($cursor === [$this->state->last_updated_at, $this->state->last_id]) {
                alert('Error: sync cursor for '.$this->table->table_name.' did not advance, skipping the rest of this table.');
                break;
            }
        } while ($rows->count() === $size);
    }

    public function resync(): void
    {
        // Truncate
        DB::connection($this->local_db)->table($this->table->table_name)->delete();

        // Sync
        $this->update();
    }

    protected function get_primary_key(): ?string
    {
        $indexes = DBSchema::connection($this->local_db)->getIndexes($this->table->table_name);

        $indexes = collect($indexes)->where('primary', true);

        if ($indexes->count() === 1) {
            return $indexes->first()['columns'][0];
        }

        return null;
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
