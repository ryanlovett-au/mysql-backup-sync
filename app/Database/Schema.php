<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;

use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;

use App\Models\Table;

class Schema
{
    public string $local_db = '';
    public string $remote_db = '';

    public array $config_tables = [];

    public array $create_local = [];
    public array $remove_local = [];
    public array $check_local = [];

    public function __construct($database, $local)
    {
        $this->local_db = $local;
        $this->remote_db = 'host_'.$database->host_id;
        $this->config_tables = $database->tables->toArray();
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

        foreach ($this->check_local as $table) {

            // Get table structures
            $remote = DB::connection($this->remote_db)->selectOne('SHOW CREATE TABLE '.$table)->{'Create Table'};
            $local = DB::connection($this->local_db)->selectOne('SHOW CREATE TABLE '.$table)->{'Create Table'};

            // $alter = Alter::compare_table_structures($remote->{'Create Table'}, $local->{'Create Table'}, $table);
            
            if ($remote != $local) {
                // dump(Alter::parse_create_table($remote));
                // dump(Alter::parse_create_table($local));
                dump($remote);
                dump('--------------------------------------');
            }

            $progress->advance();
        }

        $progress->finish(); echo "\n";
    }

    public function create_local()
    {
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
        $progress = progress(label: 'Dropping removed tables', steps: count($this->remove_local));
        $progress->start();

        foreach ($this->remove_local as $table) {
            // Drop
            DBSchema::dropIfExists($table);

            $progress->advance();
        }

        $progress->finish(); echo "\n";
    }

    public function get_views()
    {

    }

}