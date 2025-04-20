<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

use App\Database\Connect;
use App\Database\Schema;
use App\Database\Backup;
use App\Models\Host;

class Database_Backup extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Backup databases as configured.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        clear();

        $specific = null;

        if ($specific) {
            $hosts = Host::where('db_host', $specific)->with('databases.tables')->get();
        } else {
            $hosts = Host::select()->with('databases.tables')->get();
        }

        // Process each host in turn
        foreach ($hosts as $host) 
        {
            note('');
            info('Start host: '.($host->ssh_host ? $host->ssh_host : $host->db_host));

            // Connect to Database, using an SSH tunnel as require
            $connect = new Connect();

            if ($host->use_ssh_tunnel) {
                spin(message: 'Opening SSH tunnel', callback: fn () => $connect->connect_tunnel($host));
            }

            // Fot this host, process each database in turn
            foreach ($host->databases as $database) 
            {
                info('Database: '.$database->database_name."\n");

                // Create connections in the Laravel config for the remote and local instance of this database
                $connect->setup_remote_db($host, $database);
                $connect->setup_local_db($host, $database);

                // Inspect the database schema
                $schema = new Schema($database, $connect->local_db);
                $schema->get_tables_lists();

                // For tables that exist on both the remote and local, check to see if the table structure has chnged
                if (count($schema->check_local) > 0) {
                    $schema->check_tables();
                }

                // Remove any tables that have been dropped from the remote
                if (count($schema->remove_local) > 0) {
                    $schema->remove_local();
                }

                // Create any new tables locally
                if (count($schema->create_local) > 0) {
                    $schema->create_local();
                }
            }

            // Disconnect any SSH tunnels for this host
            info('End host: '.($host->ssh_host ? $host->ssh_host : $host->db_host));
            spin(message: 'Closing SSH tunnel', callback: fn () => $connect->disconnect_tunnel());
        }
    }
}
