<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Database\Action;

class Database_Backup extends Command
{
    protected $signature = 'db:backup {--skip-tz-check}';

    protected $description = 'Backup databases as configured.
                              {--skip-tz-check : Do not check for Timezone consistency between servers and proceed.}';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        Action::go($this->options());
    }
}
