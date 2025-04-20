<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Models\Table;

class Backup
{
    public string $db = '';

    public function __construct($database)
    {
        $this->db = 'host_'.$database->host_id;
    }

    public function process(): void
    {
           
    }
}