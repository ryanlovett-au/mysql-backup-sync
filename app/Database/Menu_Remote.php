<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DBSchema;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

use App\Models\Config;
use App\Models\Host;
use App\Models\Database;
use App\Models\Table;
use App\Models\State;

class Menu_Remote
{
    public static function remote_config_host($host): void
    {
    	Menu::header();

        if ($host == 'new') {
            $host = new Host();
            $host->db_host = '';
            $host->db_port = '3306';
            $host->db_username = '';
            $host->db_password = '';
        } else {
            $host = Host::find($host);
        }

    	$next = select(
            label: 'Configure Remote (Original) Host/Database - '.($host->ssh_host ? $host->ssh_host.' ('.$host->db_host.')' : $host->db_host),
            options: self::remote_config_host_options($host),
            scroll: 25,
            required: true
        );

        switch ($next) {
        	case '-':
            case '--':
            case '---':
                self::remote_config_host($host->id ?? 'new');
                break;

            case 'test':
                (new Connect())->test($host);
                break;

            case 'delete';
                $deleted = self::delete_host($host);

            case 'back':
                return;
                break;

            default:
                if (str_contains($next, 'database_')) {
                    self::remote_config_database($next, $host);
                } else {
                    $host = self::update_config($next, $host);
                }
        }

        if ($deleted ?? false) {
            return;
        }

        self::remote_config_host($host->id ?? 'new');
	}

	private static function remote_config_host_options($host): array
    {
        $columns = array_diff(DBSchema::getColumnListing('hosts'), ['id', 'created_at', 'updated_at', 'db_use_ssl', 'ssh_private_key_passphrase', 'ssh_public_key_path']);

        $len = collect($columns)->map(fn ($column) => is_string($column) ? strlen($column) : 0 )->max();

        $options = [];

        $options['-'] = '-------------------- Config ----------------------';

        foreach ($columns as $column) {
            $options[$column] = str_pad(strtoupper(str_replace('_', ' ', $column)), $len).' = '.$host->{$column};
        }

        if ($host->id) {
            $options['--'] = '------------------- Databases --------------------';
            $options['database_new'] = 'Add Remote Database';

            foreach ($host->databases as $database) {
                $options['database_'.$database->id] = $database->database_name;
            }
        }

        $options['---'] = '--------------------------------------------------';
        $options['test'] = 'Test Host Connection';
        $options['delete'] = 'Delete Host';
        $options['back'] = 'Back';

        return $options;
    }

    public static function delete_host($host): bool
    {
        if ($host->databases->count() > 0) {
            alert('You cannot delete a host with configured databases.');
            pause();
            return false;
        }

        alert('You are about to delete the record for host: '.($host->ssh_host ? $host->ssh_host.' ('.$host->db_host.')' : $host->db_host).'.');

        $confirmed = confirm(label: 'Are you sure?', default: false,);

        if ($confirmed) {
            $host->delete();
            return true;
        }

        return false;
    }

    private static function update_config(string $column, $host)
    {
        $update = text(
            label: strtoupper(str_replace('_', ' ', $column)).' = ',
            default: $host->{$column} ?? '',
            hint: 'For NULL enter an empty string...'
        );

        if ($update == 'false' || $update == 'no') { $update = '0'; }
        else if ($update == 'true' || $update == 'yes') { $update = '1'; }

        $host->{$column} = $update;

        $host->save();

        return $host;
    }

    public static function remote_config_database($database, $host): void
    {
        Menu::header();

        if ($database == 'database_new') {
            $database = new Database();
            $database->host_id = $host->id;
            $database->database_name = '';
        } else {
            $database = Database::find(str_replace('database_', '', $database));
        }

        $next = select(
            label: 'Configure Database for '.($host->ssh_host ? $host->ssh_host.' ('.$host->db_host.')' : $host->db_host),
            options: self::remote_config_database_options($database),
            scroll: 25,
            required: true
        );

        switch ($next) {
            case '-':
            case '--':
            case '---':
                self::remote_config_database($database->id ?? 'new', $host);
                break;

            case 'delete';
                $deleted = self::delete_database($database, $host);

            case 'back':
                return;
                break;

            default:
                if (str_contains($next, 'table_')) {
                    self::remote_config_tables($next, $database, $host);
                } else {
                    self::update_config_database($next, $database, $host);
                }
        }

        if ($deleted ?? false) {
            return;
        }

        self::remote_config_database($database->id ?? 'new', $host);
    }

    private static function remote_config_database_options($database): array
    {
        $columns = array_diff(DBSchema::getColumnListing('databases'), ['id', 'host_id', 'all_tables', 'created_at', 'updated_at']);

        $len = collect($columns)->map(fn ($column) => is_string($column) ? strlen($column) : 0 )->max();

        $options = [];

        $options['-'] = '-------------------- Config ----------------------';

        foreach ($columns as $column) {
            $options[$column] = str_pad(strtoupper(str_replace('_', ' ', $column)), $len).' = '.$database->{$column};
        }

        if (Table::where('database_id', $database->id)->count() > 0) {
            $options['--'] = '-------------------- Tables ----------------------';

            foreach ($database->alltables as $table) {
                $options['table_'.$table->id] = $table->table_name;
                $options['table_'.$table->id] .= !$table->is_active ? ' (inactive)' : '';
                $options['table_'.$table->id] .= $table->always_resync ? ' (resync)' : '';
            }
        }

        $options['---'] = '--------------------------------------------------';
        $options['delete'] = 'Delete Database';
        $options['back'] = 'Back';

        return $options;
    }

    public static function update_config_database(string $column, $database, $host)
    {
        $update = text(
            label: strtoupper(str_replace('_', ' ', $column)).' = ',
            default: $database->{$column} ?? '',
            hint: 'For NULL enter an empty string...'
        );

        if ($update == 'false' || $update == 'no') { $update = '0'; }
        else if ($update == 'true' || $update == 'yes') { $update = '1'; }

        $database->{$column} = $update;

        $database->save();

        // Check if we want to update tables listing
        if (Table::where('database_id', $database->id)->count() < 1) 
        {    
            info('Updating menu with table details...');

            $connect = new Connect();

            if ($host->use_ssh_tunnel) {
                spin(message: 'Opening SSH tunnel', callback: fn () => $connect->connect_tunnel($host));
            }
            
            $connect->setup_remote_db($host, $database, false);

            spin(message: 'Populating menu with table information...', callback: function () use ($database, $connect) {
                $schema = new Schema($database, $connect->remote_db);
                $tables = $schema->get_tables($connect->remote_db);
                $schema->create_tables_in_tables_db($tables);
                echo "\n";
            });
        }

        pause();

        return $database;
    }

    public static function delete_database($database, $host): bool
    {
        alert('*** WARNING ***');

        alert('You are about to delete the record for database: '.$database->database_name.'.');

        alert('This will delete all associated backed up (local) data!');

        $confirmed = confirm(label: 'Are you sure?', default: false,);

        if ($confirmed) 
        {
            // Delete local data
            $connect = new Connect();
            $connect->setup_local_db($host, $database);

            $len = 55 - iconv_strlen($database->database_name);
            $db_name = 'backup_'.substr(str_replace('.', '', $host->ssh_host ? $host->ssh_host : $host->db_host), 0, $len).'_'.$database->database_name;

            DB::connection($connect->local_db)->statement('DROP DATABASE IF EXISTS '.$db_name.';');

            // Delete all state
            State::where('host_id', $host->id)->where('database_id', $database->id)->delete();

            // Delete data from tables Table
            Table::where('database_id', $database->id)->delete();

            // Delete database record
            $database->delete();

            return true;
        }

        return false;
    }

    public static function remote_config_tables($table, $database, $host)
    {
        Menu::header();

        $table = Table::find(str_replace('table_', '', $table));

        $next = select(
            label: 'Configure Table '.$table->table_name.' for '.$database->database_name,
            options: [
                '-' => '-------------------- Config ----------------------',
                'always_resync' => 'ALWAYS RESYNC = '.$table->always_resync,
                'is_active'     => 'IS ACTIVE     = '.$table->is_active,
                '--' => '--------------------------------------------------',
                'back' => 'Back'
            ],
            scroll: 10,
            required: true
        );

        switch ($next) {
            case '-':
            case '--':
                self::remote_config_tables('table_'.$table->id, $database, $host);
                break;

            case 'back':
                return;
                break;

            default:
                self::update_config_table($next, $table);
        }

        self::remote_config_tables('table_'.$table->id, $database, $host);
    }

    public static function update_config_table(string $column, $table)
    {
        $update = text(
            label: strtoupper(str_replace('_', ' ', $column)).' = ',
            default: $table->{$column} ?? '',
            hint: 'For NULL enter an empty string...'
        );

        if ($update == 'false' || $update == 'no') { $update = '0'; }
        else if ($update == 'true' || $update == 'yes') { $update = '1'; }

        $table->{$column} = $update;

        $table->save();

        return $table;
    }
}