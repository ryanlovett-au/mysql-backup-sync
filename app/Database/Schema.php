<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\progress;

use App\Models\Table;
use App\Models\State;

class Schema
{
    public string $local_db = '';
    public string $remote_db = '';

    public array $config_tables = [];

    public array $create_local = [];
    public array $remove_local = [];
    public array $check_local = [];

    public array $tables_list = [];

    public int $host_id;
    public int $database_id;

    public function __construct($database, $local)
    {
        $this->local_db = $local;
        $this->remote_db = 'host_'.$database->host_id;
        $this->config_tables = $database->tables->toArray();

        $this->host_id = $database->host_id;
        $this->database_id = $database->id;
    }

    public function get_tables_lists(): void
    {
        $progress = progress(label: 'Comparing database schemas', steps: 2);
        $progress->start();

        // Check remote tables
        $remote = $this->get_tables($this->remote_db);

        $progress->advance();

        // Check local tables
        $local = $this->get_tables($this->local_db);

        $progress->advance();

        // Compare tables lists
        $this->create_local = array_diff($remote, $local);
        $this->remove_local = array_diff($local, $remote);
        $this->check_local = array_intersect($local, $remote);

        // Keep a canonical list of tables
        $this->tables_list = $remote;

        $progress->finish(); echo "\n";
    }

    public function get_tables($connection): array
    {
        // Get the full list of tables from the connection
        $tables = DBSchema::connection($connection)->getTables(schema: config('database.connections.'.$connection.'.database'));
        $tables = collect($tables)->pluck('name')->toArray();

        // If we are looking for all tables then just return the list
        if (count($this->config_tables) === 1 && $this->config_tables[0]['table_name'] == '[all]') {
            return $tables;
        }

        // If we are looking for a specific list of tables, filter the list
        return array_intersect($tables, $this->config_tables->pluck('table_name')->toArray());
    }

    public function check_tables()
    {
        $progress = progress(label: 'Comparing table structures', steps: count($this->check_local));
        $progress->start();

        $reset_tables = [];

        foreach ($this->check_local as $table) {

            // Get table structures
            $remote = DB::connection($this->remote_db)->selectOne('SHOW CREATE TABLE '.$table)->{'Create Table'};
            $local = DB::connection($this->local_db)->selectOne('SHOW CREATE TABLE '.$table)->{'Create Table'};
            
            // Drop variable elements from the statement
            $remote = $this->cleanup_create($remote);
            $local = $this->cleanup_create($local);

            if ($remote != $local) {
                $reset_tables[] = $table;
            }

            $progress->advance();
        }

        $progress->finish(); echo "\n";

        if (count($reset_tables) > 0) {
            error('Table structure has changed (resync required):');
            foreach ($reset_tables as $reset) {
                error(' - '.$reset);
            }

            echo "\n";
        }
        
        return $reset_tables;
    }

    protected function cleanup_create($statement)
    {
        // Trim everything after the last )
        $statement = substr($statement, 0, strrpos($statement, ')') + 1);

        $statement = str_replace('CHARACTER SET ', '', $statement);
        $statement = str_replace('COLLATE ', '', $statement);
        $statement = str_replace('utf8mb4 ', '', $statement);
        $statement = str_replace('utf8mb4_unicode_ci ', '', $statement);

        return $statement;
    }

    public function create_local()
    {
        info('Creating tables:');
        
        foreach ($this->create_local as $reset) {
            info(' - '.$reset);
        }
        
        echo "\n";

        $progress = progress(label: 'Creating new tables', steps: count($this->create_local));
        $progress->start();

        foreach ($this->create_local as $table) {
            // Get table structure
            $create = DB::connection($this->remote_db)->selectOne('SHOW CREATE TABLE '.$table);

            // Create locally
            DB::connection($this->local_db)->statement($create->{'Create Table'});

            $progress->advance();
        }

        $progress->finish(); echo "\n";
    }

    public function remove_local()
    {
        error('Dropping tables:');

        foreach ($this->remove_local as $reset) {
            error(' - '.$reset);
        }
        
        echo "\n";

        $progress = progress(label: 'Dropping removed tables', steps: count($this->remove_local));
        $progress->start();

        foreach ($this->remove_local as $table) {
            // Drop
            DBSchema::connection($this->local_db)->dropIfExists($table);

            // Remove state
            State::where('host_id', $this->host_id)->where('database_id', $this->database_id)->where('table_name', $table)->delete();

            $progress->advance();
        }

        $progress->finish(); echo "\n";
    }

    public function get_views()
    {

    }

}