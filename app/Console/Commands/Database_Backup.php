<?php

namespace App\Console\Commands;

use App\Database\Action;
use Illuminate\Console\Command;

use function Laravel\Prompts\note;

class Database_Backup extends Command
{
    protected $signature = 'db:backup {--skip-tz-check} {--host=} {--database=}';

    protected $description = 'Backup databases as configured
                              {--host : The host name to backup}
                              {--database : The database name to backup (must also specify a host name)}
                              {--skip-tz-check : Do not check for Timezone consistency between servers and proceed}';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        if (! is_null($this->option('database')) && is_null($this->option('host'))) {
            note('If specifying a database, you must also specify a host');
            exit;
        }

        // Same as the menu does - a scheduled run needs its own database brought up to date too
        $this->call('migrate', ['--quiet' => true, '--force' => true]);

        Action::go($this->options(), true, $this->option('host'), $this->option('database'));
    }
}
