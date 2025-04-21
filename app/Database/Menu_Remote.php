<?php

namespace App\Database;

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

use App\Models\Config;
use App\Models\Host;
use App\Models\Database;
use App\Models\Table;

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
            scroll: 20,
            required: true
        );

        switch ($next) {
        	case '-':
            case '--':
            case '---':
                self::remote_config_host($host->id ?? 'new');
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
        $columns = DBSchema::getColumnListing('hosts');

        $len = collect($columns)->map(fn ($column) => is_string($column) ? strlen($column) : 0 )->max();

        $options = [];

        $options['-'] = '-------------------- Config ----------------------';

        foreach ($columns as $column) {
            if (in_array($column, ['id', 'created_at', 'updated_at'])) {
                continue;
            }

            $options[$column] = str_pad(strtoupper(str_replace('_', ' ', $column)), $len).' = '.$host->{$column};
        }

        if ($host->id) {
            $options['--'] = '------------------- Databases --------------------';

            foreach ($host->databases as $database) {
                $options['database_'.$database->id] = $database->database_name;
            }
        }

        $options['---'] = '--------------------------------------------------';
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
            default: $host->{$column},
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

        if ($database == 'new') {
            $database = new Database();
            $database->database_name = '';
        } else {
            $database = Database::find(str_replace('database_', '', $database));
        }

        $next = select(
            label: 'Configure Database for '.($host->ssh_host ? $host->ssh_host.' ('.$host->db_host.')' : $host->db_host),
            options: self::remote_config_database_options($database),
            scroll: 20,
            required: true
        );

        switch ($next) {
            case '-':
            case '--':
            case '---':
                self::remote_config_database($database->id ?? 'new', $host);
                break;

            case 'delete';
                $deleted = self::delete_database($database);

            case 'back':
                return;
                break;

            default:
                if (str_contains($next, 'table_')) {
                    self::remote_config_tables($next, $database, $host);
                } else {
                    self::update_config_database($next, $database);
                }
        }

        if ($deleted ?? false) {
            return;
        }

        self::remote_config_database($database->id ?? 'new', $host);
    }

    private static function remote_config_database_options($database): array
    {
        $columns = DBSchema::getColumnListing('databases');

        $len = collect($columns)->map(fn ($column) => is_string($column) ? strlen($column) : 0 )->max();

        $options = [];

        $options['-'] = '-------------------- Config ----------------------';

        foreach ($columns as $column) {
            if (in_array($column, ['id', 'host_id', 'created_at', 'updated_at'])) {
                continue;
            }

            $options[$column] = str_pad(strtoupper(str_replace('_', ' ', $column)), $len).' = '.$database->{$column};
        }

        if ($database->id) {
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

    public static function update_config_database(string $column, $database)
    {
        $update = text(
            label: strtoupper(str_replace('_', ' ', $column)).' = ',
            default: $database->{$column},
            hint: 'For NULL enter an empty string...'
        );

        if ($update == 'false' || $update == 'no') { $update = '0'; }
        else if ($update == 'true' || $update == 'yes') { $update = '1'; }

        $database->{$column} = $update;

        $database->save();

        return $database;
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
            default: $table->{$column},
            hint: 'For NULL enter an empty string...'
        );

        if ($update == 'false' || $update == 'no') { $update = '0'; }
        else if ($update == 'true' || $update == 'yes') { $update = '1'; }

        $table->{$column} = $update;

        $table->save();

        return $table;
    }
}