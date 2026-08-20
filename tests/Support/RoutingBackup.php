<?php

namespace Tests\Support;

use App\Database\Backup;

/** A Backup that records which way action() sent it, rather than doing any work. */
class RoutingBackup extends Backup
{
    public array $called = [];

    public function __construct() {}

    public function update(): void
    {
        $this->called[] = 'update';
    }

    public function resync(): void
    {
        $this->called[] = 'resync';
    }
}
