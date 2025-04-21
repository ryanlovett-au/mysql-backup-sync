<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config as AppConfig;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\note;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;

use App\Models\Config;
use App\Models\Host;

class Connect
{
    public $tunnel = null;
    public $remote_db = null;
    public $local_db = null;
    public $local_port = '6448';

    public function connect_tunnel($host): void
    {
        // Get local port to use
        $this->local_port = $this->get_tunnel_port();

        // Command
        $command = ['ssh', '-N', '-L', $this->local_port.':'.$host->db_host.':'.$host->db_port, $host->ssh_username.'@'.$host->ssh_host, '-p', $host->ssh_port];

        // Authenticate
        if (!empty($host->ssh_public_key_path) && !empty($host->ssh_private_key_path)) {
            $command = array_merge($command, ['-i', $host->ssh_private_key_path]);

            // if (!empty($host->ssh_private_key_passphrase)) {
            //     $command = $command + ['-o', 'IdentityFile='.$host->ssh_private_key_passphrase]; // May be needed for passphrase
            // }
        } else if (!empty($host->ssh_password)) {
            $command = ['sshpass', '-p', $host->ssh_password] + $command;
        }

        // Tunnel
        $this->tunnel = new Process($command);
        $this->tunnel->start();

        // Check
        $i = 10;
        while (!$this->check_tunnel() && $i > 0) {
            sleep(1);
            $i--;
        }

        note('');
    }

    public function get_tunnel_port()
    {
        $port = rand(6448, 9999);

        $check = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        
        if (is_resource($check)) {
            fclose($check);
            return $this->get_tunnel_port();
        }
        
        return $port;
    }

    public function check_tunnel(): bool
    {
        $connection = @fsockopen('127.0.0.1', $this->local_port, $errno, $errstr, 1);

        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }

        return false;
    }

    public function disconnect_tunnel(): void
    {
        exec("kill " . escapeshellarg($this->tunnel->getPid()), $output, $code);
        $this->tunnel = null;

        note('');
    }

    public function setup_remote_db($host, $database): void
    {
        $progress = progress(label: 'Configuring remote (original) database', steps: 1);
        $progress->start();

        // DB Connect
        $connect = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => $this->local_port,
            'database' => $database->database_name,
            'username' => $host->db_username,
            'password' => $host->db_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'prefix' => '',
            'prefix_indexes' => true,
            'engine' => null,
            'options' => [],
        ];

        $progress->advance();

        $this->remote_db = 'host_'.$host->id;

        AppConfig::set('database.connections.'.$this->remote_db, $connect);

        $progress->finish(); echo "\n";
    }

    public function setup_local_db($host, $database, $create = true): void
    {
        $progress = progress(label: 'Configuring local (backup) database', steps: 1);
        $progress->start();

        // Format local db names (max 64 utf8 characters)
        $len = 55 - iconv_strlen($database->database_name);
        $db_name = 'backup_'.substr(str_replace('.', '', $host->ssh_host ? $host->ssh_host : $host->db_host), 0, $len).'_'.$database->database_name;

        // DB Connect
        $connect = [
            'driver' => 'mysql',
            'host' => Config::get('backup_db_host'),
            'port' => Config::get('backup_db_port'),
            'database' => $db_name,
            'username' => Config::get('backup_db_username'),
            'password' => Config::get('backup_db_password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'prefix' => '',
            'prefix_indexes' => true,
            'engine' => null,
            'options' => [],
        ];

        $this->local_db = 'host_'.$host->id.'_local';

        AppConfig::set('database.connections.'.$this->local_db, $connect);

        $progress->advance();

        // Check that the databse exists
        try {
            DB::connection($this->local_db)->getPDO();
        } catch (\Exception $e) {
            // Database not created
            if ($e->errorInfo[1] == 1049) {
                // Create missing database
                if ($create) {
                    // Null out databse
                    $connect['database'] = null;
                    AppConfig::set('database.connections.'.$this->local_db, $connect);

                    // Reconnect and create database
                    DB::reconnect($this->local_db)->statement('CREATE DATABASE IF NOT EXISTS '.$db_name.';');

                    // Reapply database name
                    $connect['database'] = $db_name;
                    AppConfig::set('database.connections.'.$this->local_db, $connect);

                    // Disconnect to force reconnection next time
                    DB::disconnect($this->local_db);
                } else {
                    alert('Error: Local database does not exist and not able to be created.');

                    $this->disconnect_tunnel();
                    
                    exit(1);
                }
            }
        }

        $progress->finish(); echo "\n";
    }

    public function check_tz()
    {
        $remote = DB::connection($this->remote_db)->selectOne('SELECT @@global.time_zone')->{'@@global.time_zone'};

        if ($remote == 'SYSTEM') {
            $remote = DB::connection($this->remote_db)->selectOne('SELECT @@system_time_zone')->{'@@system_time_zone'};
        }

        $local = DB::connection($this->local_db)->selectOne('SELECT @@global.time_zone')->{'@@global.time_zone'};

        if ($local == 'SYSTEM') {
            $local = DB::connection($this->local_db)->selectOne('SELECT @@system_time_zone')->{'@@system_time_zone'};
        }

        if ($remote != $local) {
            alert('Error: Local database timezone ('.$local.') does not match remote database timezone ('.$remote.'). This will likely cause issues with datetime object synchronisation during DST transitions. Please correct this issue.');

            $this->disconnect_tunnel();

            exit(1);
        }
    }
}