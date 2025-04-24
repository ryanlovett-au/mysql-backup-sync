<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\pause;

use App\Models\Table;
use App\Models\State;

class Backup
{
    public string $local_db = '';
    public string $remote_db = '';
    public $table = null;
    public $state = null;

    public function __construct($database, $local, $table)
    {
        $this->local_db = $local;
        $this->remote_db = 'host_'.$database->host_id;
        
        $this->table = Table::where('database_id', $database->id)
            ->where('table_name', $table)
            ->first();

        if (!$this->table) {
            $create = new Table();
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
        if (!$this->table) {
            alert('Table config not found, consider re-sycning tables.');
            
            if (!$cli) {
                pause();
            }
            
            return;
        }

        if (!$this->table->is_active) {
            return;
        }

        if (($this->state->last_id || $this->state->last_updated_id) && !$this->table->always_resync) {
            $this->update();
            return;
        }

        $this->resync();
        return;
    }

    public function has_timestamps(): bool
    {
        return DBSchema::connection($this->remote_db)->hasColumn($this->table->table_name, 'updated_at');
    }

    public function update(): void
    {
        // Determine how to manage state
        $timestamps = $this->has_timestamps();
        $primary_key = $this->get_primary_key();

        // Determine which query type to use
        if ($this->table->always_resync || $primary_key == null) {
            $query = DB::connection($this->remote_db)
                ->table($this->table->table_name);

            // Order by the primary key or by the first column if no primary key
            if (!empty($primary_key)) {
                $query->orderBy($primary_key);
            } else {
                $query->orderBy(
                    DBSchema::connection($this->remote_db)
                        ->getColumnListing($this->table->table_name)[0],
                    'asc'
                );
            }

            $count = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->count();
        }

        else if ($timestamps && !$this->table->always_primary_key) {
            if (empty($this->state->last_updated_at)) {
                $this->state->last_updated_at = '1900-01-01 00:00:01';
            }

            $query = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where('updated_at', '>=', $this->state->last_updated_at)
                ->orderBy('updated_at', 'asc');

            $count = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where('updated_at', '>=', $this->state->last_updated_at)
                ->count();
        }

        else {
            if (empty($this->state->last_id)) {
                $this->state->last_id = 0;
            }

            $query = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where($primary_key, '>', $this->state->last_id)
                ->orderBy($primary_key, 'asc');

            $count = DB::connection($this->remote_db)
                ->table($this->table->table_name)
                ->where($primary_key, '>', $this->state->last_id)
                ->count();
        }

        // Are we going??
        if ($count > 0) {
            $progress = progress(label: ($this->table->always_resync ? 'Resyncing' : 'Updating').' '.$this->table->table_name, steps: $count);
            $progress->start();

            // Get the data
            $query->chunk(500, function ($rows) use ($progress, $primary_key, $timestamps) {
            
                // Cast rows to arrays
                $rows = array_map(function ($row) {
                    return (array) $row;
                }, $rows->toArray());

                // Insert
                DB::connection($this->local_db)
                    ->table($this->table->table_name)
                    ->upsert($rows, [],
                        DBSchema::connection($this->local_db)
                            ->getColumnListing($this->table->table_name)
                    );

                // Update state as we go - only use primary key as that is how we are getting source rows
                $last = end($rows);
                $this->state->last_updated_at = $timestamps ? $last['updated_at'] : null;
                $this->state->last_id = $last[$primary_key] ?? null;
                $this->state->save();

                $progress->advance(count($rows));
            });

            $progress->finish(); echo "\n";
        }
    }

    public function resync(): void
    {
        // Truncate
        DB::connection($this->local_db)->table($this->table->table_name)->delete();

        // Sync
        $this->update();
    }

    protected function get_primary_key(): string|null
    {
        $indexes = DBSchema::connection($this->local_db)->getIndexes($this->table->table_name);

        $indexes = collect($indexes)->where('primary', true);

        if ($indexes->count() === 1) {
            return $indexes->first()['columns'][0];
        }

        return null;
    }

    protected function get_last_id(): int|null
    {
        return DB::connection($this->local_db)->table($this->table->table_name)->orderBy('id', 'desc')->first()?->id;
    }

    protected function get_last_update_at(): string|null
    {
        if ($this->has_timestamps()) {
            return DB::connection($this->local_db)->table($this->table->table_name)->orderBy('updated_at', 'desc')->first()?->updated_at;
        }

        return null;
    }
}