<?php

namespace App\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config as AppConfig;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\note;
use function Laravel\Prompts\alert;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

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
        $command = ['ssh', '-C', '-N', '-L', $this->local_port.':'.$host->db_host.':'.$host->db_port, $host->ssh_username.'@'.$host->ssh_host, '-p', $host->ssh_port];

        // Authenticate
        if (!empty($host->ssh_private_key_path)) {
            $command = array_merge($command, ['-i', $host->ssh_private_key_path]);

            // if (!empty($host->ssh_private_key_passphrase)) {
            //     $command = $command + ['-o', 'IdentityFile='.$host->ssh_private_key_passphrase]; // May be needed for passphrase
            // }
        } else if (!empty($host->ssh_password)) {
            $command = array_merge(['sshpass', '-p', $host->ssh_password], $command);
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
        if (is_numeric($this->tunnel->getPid())) {
            exec("kill " . escapeshellarg($this->tunnel->getPid()), $output, $code);
            $this->tunnel = null;
        }

        note('');
    }

    public function setup_remote_db($host, $database): void
    {
        $progress = progress(label: 'Configuring source (remote) database', steps: 1);
        $progress->start();

        // Disconnect previous connections and reset config
        if (!empty($this->remote_db)) {
            DB::purge($this->remote_db);
            $this->remote_db = null;
        }

        // DB Connect
        $connect = [
            'driver' => 'mysql',
            'host' => $host->use_ssh_tunnel ? '127.0.0.1' : $host->db_host,
            'port' => $host->use_ssh_tunnel ? $this->local_port : $host->db_port,
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
        $progress = progress(label: 'Configuring destination (local) database', steps: 1);
        $progress->start();

        // Disconnect previous connections and reset config
        if (!empty($this->local_db)) {
            DB::purge($this->local_db);
            $this->local_db = null;
        }

        // Format local db names (max 64 utf8 characters)
        if (Config::get('keep_db_names')) {
            $db_name = $database->database_name;
        } else {
            $len = 55 - iconv_strlen($database->database_name);
            $db_name = 'backup_'.substr(str_replace('.', '', $host->ssh_host ? $host->ssh_host : $host->db_host), 0, $len).'_'.$database->database_name;
        }

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
                    alert('Error: Destination database does not exist and not able to be created.');

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

        if ($remote == '+00:00') {
            $remote = 'UTC';
        }

        $local = DB::connection($this->local_db)->selectOne('SELECT @@global.time_zone')->{'@@global.time_zone'};

        if ($local == 'SYSTEM') {
            $local = DB::connection($this->local_db)->selectOne('SELECT @@system_time_zone')->{'@@system_time_zone'};
        }

        if ($local == '+00:00') {
            $local = 'UTC';
        }

        if ($remote != $local) {
            alert('Error: Destination database timezone ('.$local.') does not match source database timezone ('.$remote.'). This will likely cause issues with datetime object synchronisation during DST transitions. Please correct this issue.');

            $this->disconnect_tunnel();

            exit(1);
        }
    }

    public function test($host): void
    {
        if ($host->use_ssh_tunnel) {
            spin(message: 'Opening SSH tunnel', callback: fn () => $this->connect_tunnel($host));
        }

        $connect = [
            'driver' => 'mysql',
            'host' => $host->use_ssh_tunnel ? '127.0.0.1' : $host->db_host,
            'port' => $host->use_ssh_tunnel ? $this->local_port : $host->db_port,
            'database' => null,
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

        AppConfig::set('database.connections.test', $connect);

        try {
            DB::connection('test')->getPDO();
            info('Connection successful!');
        } catch (\Exception $e) {
            error('Connection FAILED');
        }

        if ($host->use_ssh_tunnel) {
            spin(message: 'Closing SSH tunnel', callback: fn () => $this->disconnect_tunnel());
        }

        pause();

        return;
    }
}