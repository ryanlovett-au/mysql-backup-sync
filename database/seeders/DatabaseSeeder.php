<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('config')->insert([
            ['key' => 'backup_db_host', 'value' => null],
            ['key' => 'backup_db_port', 'value' => null],
            ['key' => 'backup_db_username', 'value' => null],
            ['key' => 'backup_db_password', 'value' => null],
            ['key' => 'skip_tz_check', 'value' => 0],
        ]);
    }
}
