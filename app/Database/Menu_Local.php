<?php

namespace App\Database;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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
            $options[$conf->key] = str_pad(strtoupper(str_replace('_', ' ', $conf->key)), $len).' = '.$conf->value;
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

        $update = text(
            label: strtoupper(str_replace('_', ' ', $config->key)).' = ',
            default: $config->value ?? '',
            hint: 'For NULL enter an empty string...'
        );

        if ($update == 'false' || $update == 'no') { $update = '0'; }
        else if ($update == 'true' || $update == 'yes') { $update = '1'; }

        $config->value = $update;
        $config->save();

        return;
    }
}