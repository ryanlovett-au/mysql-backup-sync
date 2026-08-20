<?php

namespace Tests\Support;

use App\Database\Backup;
use App\Models\State;
use App\Models\Table as TableModel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Prompts\Prompt;
use PDO;
use ReflectionClass;
use Symfony\Component\Console\Output\NullOutput;

trait BuildsBackups
{
    protected ?int $output_level = null;

    /** The application writes newlines straight to stdout, which the prompt output cannot swallow. */
    protected function captureAppOutput(): void
    {
        $this->output_level = ob_get_level();
        ob_start();
    }

    protected function releaseAppOutput(): void
    {
        while (! is_null($this->output_level) && ob_get_level() > $this->output_level) {
            ob_end_clean();
        }

        $this->output_level = null;
    }

    protected function setUpConnections(callable $source_schema, string $destination_ddl): void
    {
        // Progress bars and spinners have nothing to say to a test runner
        Prompt::setOutput(new NullOutput);

        StubConnection::reset();

        DB::extend('stub', function ($config) use ($destination_ddl) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec($destination_ddl);

            return new StubConnection($pdo, ':memory:', '', $config);
        });

        Config::set('database.connections.dest', ['driver' => 'stub', 'database' => ':memory:']);
        Config::set('database.connections.src', ['driver' => 'sqlite', 'database' => ':memory:']);

        DB::purge('dest');
        DB::purge('src');

        $source_schema(Schema::connection('src'));

        $this->captureAppOutput();
    }

    protected function sourceRows(string $table, int $count, bool $timestamps = true): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $row = ['id' => $i, 'ref' => 'r'.$i];

            if ($timestamps) {
                $row['updated_at'] = '2025-06-01 09:00:00';
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('src')->table($table)->insert($chunk);
        }
    }

    protected function makeBackup(string $table, array $table_config = [], array $state = []): Backup
    {
        $row = new TableModel;
        $row->database_id = 1;
        $row->table_name = $table;

        foreach ($table_config as $key => $value) {
            $row->{$key} = $value;
        }

        $row->save();

        $s = new State;
        $s->host_id = 1;
        $s->database_id = 1;
        $s->table_name = $table;
        $s->last_id = $state['last_id'] ?? null;
        $s->last_updated_at = $state['last_updated_at'] ?? null;
        $s->save();

        $backup = (new ReflectionClass(Backup::class))->newInstanceWithoutConstructor();
        $backup->local_db = 'dest';
        $backup->remote_db = 'src';
        $backup->database_name = 'testdb';
        $backup->table = $row;
        $backup->state = $s;

        return $backup;
    }

    protected function config(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('config')->updateOrInsert(['key' => $key], ['field_type' => 'text', 'value' => $value]);
        }
    }
}
