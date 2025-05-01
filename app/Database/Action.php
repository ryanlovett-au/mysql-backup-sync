<?php

namespace App\Database;

use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\alert;

use App\Database\Connect;
use App\Database\Schema;
use App\Database\Backup;
use App\Models\Host;
use App\Models\Config;

class Action
{
	public static function go(array $options = [], bool $cli = false, string $specified_host = null, string $specified_database = null): void
	{
        clear();

        // Lets gooooo
        $hosts = Host::select()->with('databases.tables')->get();

        // Process each host in turn
        foreach ($hosts as $host) 
        {
            // Allow for specifying specific hosts
            if (!is_null($specified_host)) {
                if (($host->ssh_host ? $host->ssh_host : $host->db_host) != $specified_host) {
                    continue;                    
                }
            }

            if ($host->databases->count() === 0) {
            	continue;
            }

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
                // Allow for specifying specific databases
                if (!is_null($specified_database) && ($database->database_name != $specified_database)) {
                    continue;
                }

                info('Database: '.$database->database_name."\n");

                // Create connections in the Laravel config for the remote and local instance of this database
                $connect->setup_remote_db($host, $database);
                $connect->setup_local_db($host, $database);

                // Check remote and local server timezones match
                if (!in_array('skip-tz-check', $options) && (!is_null(Config::get('skip_tz_check')) && Config::get('skip_tz_check') == '0')) {
                    $connect->check_tz();
                }

                // Inspect the database schema
                $schema = new Schema($database, $connect->local_db);
                $schema->get_tables_lists();

                // For tables that exist on both the remote and local, check to see if the table structure has chnaged
                if (count($schema->check_local) > 0) {
                    $reset_tables = $schema->check_tables();

                    // If the structure has changed, we dont have much choice but to drop, re-create and resync the tables
                    $schema->remove_local = array_merge($schema->remove_local, $reset_tables);
                    $schema->create_local = array_merge($schema->create_local, $reset_tables);
                }

                // Remove any tables that have been dropped from the remote
                if (count($schema->remove_local) > 0) {
                    $schema->remove_local();
                }

                // Create any new tables locally
                if (count($schema->create_local) > 0) {
                    $schema->create_local();
                }

                // GO!
                foreach ($schema->tables_list as $table) 
                {
                    $backup = new Backup($database, $connect->local_db, $table);

                    try {
	                    $backup->action($cli);
	                } catch (\Exception $e) {
                        if ($e->errorInfo[1] == 2006) {
                            alert('Error: Remote server reported out of memory error.');
                            alert('This is most commonly due to sorts of non-indexed columns in tables with large row sizes.');
                            alert('The last table attempted above is the table that generated the error.');
                            alert('Check that your updated_at column is indexed for this table, or set this table to always resync.');
                        } else {
                            alert('Error: '.$e->errorInfo[1]);
                        }

	                	self::notify($database, false);
                        if (!$cli) { pause(); }
                        break;
	                }
                }

                // Notify success
                self::notify($database);
            }

            // Disconnect any SSH tunnels for this host
            info('End host: '.($host->ssh_host ? $host->ssh_host : $host->db_host));
            spin(message: 'Closing SSH tunnel', callback: fn () => $connect->disconnect_tunnel());
        }
	}

	public static function notify($database, $success = true)
	{
		if ($success) {
			$url = $database->webhook_success;
		} else {
			$url = $database->webhook_failure;
		}

		if (!empty($url)) {
			Http::get($url);
		}
	}
}