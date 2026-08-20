<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The application's own database is a real file holding real host credentials. Tests get
        // their own, in memory, and must never be able to reach the real one.
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        if (config('database.connections.sqlite.database') !== ':memory:') {
            $this->fail('refusing to run against the real application database');
        }

        Artisan::call('migrate', ['--force' => true]);
    }
}
