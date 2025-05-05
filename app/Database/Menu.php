<?php

namespace App\Database;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;

use App\Models\Config;
use App\Models\Host;
use App\Models\Database;
use App\Models\Table;

class Menu
{
    public static function header(): void
    {
    	clear();
        note('');
    	info('*** MySQL DB Backup/Sync ***');
    	note(' https://github.com/ryanlovett-au');
    }

    public static function home(): void
    {
    	self::header();

    	$next = select(
            label: 'Main Menu',
            options: self::home_options(),
            scroll: 20,
            required: true
        );

        switch ($next) {
        	case 'backup':
        		Action::go();
        		break;

        	case 'config':
        		self::config();
        		break;

            case 'exit':
                info('Ok, bye...');
                exit;
        }

        self::home();
	}

	private static function home_options(): array
    {
        $options = [];

        $options['backup'] = 'Run Backup/Sync';
        $options['config'] = 'Configuration';
        $options['exit'] = 'Exit';

        return $options;
    }

    public static function config(): void
    {
    	self::header();

    	$next = select(
            label: 'Configuration',
            options: self::config_options(),
            scroll: 10,
            required: true
        );

        switch ($next) {
        	case 'local':
        		Menu_Local::local_config();
        		break;

        	case 'add':
        	case 'add2':
        		Menu_Remote::remote_config_host('new');
        		break;

            case '-':
            case '--':
                self::config();
                break;

            case 'back':
                return;
                break;

            default:
            	Menu_Remote::remote_config_host($next);
        }

        self::config();
	}

    private static function config_options(): array
    {
        $options = [];

        $options['local'] = 'Configure Destination (Local) Host/Database';
        $options['-'] = '----------------- Remote Hosts -------------------';
        $options['add'] = 'Add Remote Host/Database';

        if ($hosts = Host::all()) {
        	foreach ($hosts as $host) {
        		$options[$host->id] = $host->ssh_host ? $host->ssh_host.' ('.$host->db_host.')' : $host->db_host;
        	}
        } else {
        	$options['add2'] = 'No Hosts Configured';
        }

        $options['--'] = '--------------------------------------------------';
        $options['back'] = 'Back';

        return $options;
    }

}