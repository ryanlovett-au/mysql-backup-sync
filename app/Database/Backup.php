<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;

use function Laravel\Prompts\progress;

use App\Models\Table;
use App\Models\State;

class Backup
{
    public string $local_db = '';
    public string $remote_db = '';
    public string $table = '';
    public $state = null;

    public function __construct($database, $local, $table)
    {
        $this->local_db = $local;
        $this->remote_db = 'host_'.$database->host_id;
        $this->table = $table;

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

    public function action()
    {
        if ($this->has_timestamps() && $this->state->id) {
            $this->update();
            return;
        }

        $this->resync();
        return;
    }

    public function has_timestamps(): bool
    {
        return DBSchema::connection($this->remote_db)->hasColumn($this->table, 'updated_at');
    }

    public function update(): void
    {

    }

    public function resync(): void
    {
        // Truncate
        DB::connection($this->local_db)->table($this->table)->delete();

        // Check remote row
        $count = DB::connection($this->remote_db)->table($this->table)->count();

        if ($count > 0) {
            $progress = progress(label: 'Syncing '.$this->table, steps: $count);
            $progress->start();

            // Get remote rows
            $query = DB::connection($this->remote_db)->table($this->table);

            // Try and get them in some semblance of an order
            if ($primary_key = $this->get_primary_key()) {
                $query->orderBy($primary_key, 'asc');
            }
            
            // Get the data
            $query->chunk(100, function ($rows) use ($progress) {
            
                // Cast rows to arrays
                $rows = array_map(function ($row) {
                    return (array) $row;
                }, $rows->toArray());

                // Insert
                DB::connection($this->local_db)
                    ->table($this->table)
                    ->upsert($rows, [],
                        DBSchema::connection($this->local_db)
                            ->getColumnListing($this->table)
                    );

                $progress->advance(count($rows));
            });

            $progress->finish(); echo "\n";

            // Update state table
            if ($this->has_timestamps()) {
                $this->state->last_updated_at = DB::connection($this->local_db)->table($this->table)->orderBy('updated_at', 'desc')->value('updated_at');
            }

            if ($key = $this->get_primary_key()) {
                $this->state->last_id = DB::connection($this->local_db)->table($this->table)->orderBy($key, 'desc')->value($key);
            }

            $this->state->save();
        }
    }

    protected function get_primary_key(): string|null
    {
        $indexes = DBSchema::connection($this->local_db)->getIndexes($this->table);

        $indexes = collect($indexes)->where('primary', true);

        if ($indexes->count() === 1) {
            return $indexes->first()['columns'][0];
        }

        return null;
    }

    protected function get_last_id(): int|null
    {
        return DB::connection($this->local_db)->table($this->table)->orderBy('id', 'desc')->first()?->id;
    }

    protected function get_last_update_at(): string|null
    {
        if ($this->has_timestamps()) {
            return DB::connection($this->local_db)->table($this->table)->orderBy('updated_at', 'desc')->first()?->updated_at;
        }

        return null;
    }
}