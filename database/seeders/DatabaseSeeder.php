<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

use App\Models\Config;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Inital set
        Config::firstOrCreate(['key' => 'backup_db_host'], ['key' => 'backup_db_host', 'field_type' => 'text', 'value' => null]);
        Config::firstOrCreate(['key' => 'backup_db_port'], ['key' => 'backup_db_port', 'field_type' => 'text', 'value' => null]);
        Config::firstOrCreate(['key' => 'backup_db_username'], ['key' => 'backup_db_username', 'field_type' => 'text', 'value' => null]);
        Config::firstOrCreate(['key' => 'backup_db_password'], ['key' => 'backup_db_password', 'field_type' => 'text', 'value' => null]);
        Config::firstOrCreate(['key' => 'skip_tz_check'], ['key' => 'skip_tz_check', 'field_type' => 'text', 'value' => null]);

        // Added around v0.2.1
        Config::firstOrCreate(['key' => 'always_resync_tables'], ['key' => 'always_resync_tables', 'field_type' => 'textarea', 'value' => null]);
        Config::firstOrCreate(['key' => 'always_inactive_tables'], ['key' => 'always_inactive_tables', 'field_type' => 'textarea', 'value' => null]);

        // Added around v0.3
        Config::firstOrCreate(['key' => 'always_use_primary_key'], ['key' => 'always_use_primary_key', 'field_type' => 'textarea', 'value' => null]);

        // Added around v0.4
        Config::firstOrCreate(['key' => 'select_count'], ['key' => 'select_count', 'field_type' => 'text', 'value' => 50000]);
        Config::firstOrCreate(['key' => 'update_count'], ['key' => 'update_count', 'field_type' => 'text', 'value' => 2500]);

        // Added around 0.5
        Config::firstOrCreate(['key' => 'keep_db_names'], ['key' => 'keep_db_names', 'field_type' => 'text', 'value' => null]);
    } 
}