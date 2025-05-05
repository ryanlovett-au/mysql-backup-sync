<?php

namespace App\Database;

use Illuminate\Support\Str;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

use App\Models\Config;
use App\Models\Host;
use App\Models\Database;
use App\Models\Table;

class Menu_Local
{
    public static function local_config(): void
    {
    	Menu::header();

    	$next = select(
            label: 'Configure Local (Backup) Host/Database',
            options: self::local_config_options(),
            scroll: 20,
            required: true
        );

        switch ($next) {
        	case '-':
            case '--':
                self::local_config();
                break;

            case 'back':
                return;
                break;

            default:
                self::update_config($next);
        }

        self::local_config();
	}

	private static function local_config_options(): array
    {
        $config = Config::all();

        $options = [];

        $options['-'] = '-------------------- Config ----------------------';

        $len = $config->pluck('key')->map(fn ($key) => is_string($key) ? strlen($key) : 0 )->max();

        foreach ($config as $conf) {
            $options[$conf->key] = str_pad(strtoupper(str_replace('_', ' ', $conf->key)), $len).' = '.Str::limit(str_replace(["\n", "\r\n", "\r"], ' ', $conf->value), 50);
        }

        $options['--'] = '--------------------------------------------------';
        $options['back'] = 'Back';

        return $options;
    }

    private static function update_config(string $key): void
    {
        $config = Config::where('key', $key)->first();

        if (!$config) {
            return;
        }

        if ($config->field_type == 'textarea') {
            $update = textarea(
                label: strtoupper(str_replace('_', ' ', $config->key)).' = ',
                default: $config->value ?? '',
                hint: 'A list of table names separated by newlines...'
            );

            $confirmed = confirm('All matching tables in the source databases config will now be updated with this setting. Are you sure?');

            if ($confirmed) {
                $config->value = $update;
                $config->save();

                // Get array of table names
                $tables = explode(' ', str_replace(["\n", "\r\n", "\r"], ' ', $update));

                foreach ($tables as $table) {
                    if ($config->key == 'always_resync_tables') {
                        Table::where('table_name', $table)->update(['always_resync' => 1]);
                    }

                    elseif ($config->key == 'always_inactive_tables') {
                        Table::where('table_name', $table)->update(['is_active' => 0]);
                    }

                    elseif ($config->key == 'always_use_primary_key') {
                        Table::where('table_name', $table)->update(['always_primary_key' => 1]);
                    }
                }
            }
        }

        else {
            $update = text(
                label: strtoupper(str_replace('_', ' ', $config->key)).' = ',
                default: $config->value ?? '',
                hint: 'For NULL enter an empty string...'
            );

            if ($update == 'false' || $update == 'no') { $update = '0'; }
            else if ($update == 'true' || $update == 'yes') { $update = '1'; }

            $config->value = $update;
            $config->save();
        }

        return;
    }
}