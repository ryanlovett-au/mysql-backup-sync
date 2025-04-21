<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Database\Menu;

class Database_Menu extends Command
{
    protected $signature = 'db:menu';

    protected $description = 'Run DB Backup/Sync UI';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        Menu::home();
    }
}
