<?php

namespace Tests\Support;

use Illuminate\Database\SQLiteConnection;
use PDOException;

/**
 * A destination that behaves like a real one until we tell it not to. sqlite underneath, so schema
 * introspection is genuine, but writes go through here where we can make the server refuse them the
 * way MySQL does - which is all the application ever inspects, an exception carrying an error code.
 *
 * Laravel routes truncate() through statement() and delete()/upsert() through affectingStatement(),
 * which is how the two are told apart here, since sqlite spells a truncate as a delete.
 */
class StubConnection extends SQLiteConnection
{
    public static int $refuse_writes_over = 0;

    public static int $refuse_writes_with = 1153;

    public static bool $refuse_truncate = false;

    public static array $log = [];

    public static array $batches = [];

    /** @var callable|null fired after each accepted write, for interrupting mid sync */
    public static $after_write = null;

    public static function reset(): void
    {
        self::$refuse_writes_over = 0;
        self::$refuse_writes_with = 1153;
        self::$refuse_truncate = false;
        self::$log = [];
        self::$batches = [];
        self::$after_write = null;
    }

    public static function rows_written(): int
    {
        return array_sum(self::$batches);
    }

    protected static function refusal(int $code, string $message): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = ['HY000', $code, $message];

        return $e;
    }

    public function statement($query, $bindings = [])
    {
        if (str_contains(strtolower($query), 'sqlite_sequence')) {
            return true;
        }

        if (self::$refuse_truncate) {
            self::$log[] = 'truncate refused';

            throw self::refusal(1701, 'Cannot truncate a table referenced in a foreign key constraint');
        }

        self::$log[] = 'truncate';

        return parent::statement($query, $bindings);
    }

    public function affectingStatement($query, $bindings = [])
    {
        $sql = strtolower($query);

        if (str_contains($sql, 'insert')) {
            if (self::$refuse_writes_over > 0 && count($bindings) > self::$refuse_writes_over) {
                self::$log[] = 'write refused';

                throw self::refusal(self::$refuse_writes_with, 'statement too large');
            }

            self::$log[] = 'write';
            self::$batches[] = $this->rows_in($query, $bindings);

            if (self::$after_write) {
                (self::$after_write)();
            }

            return count($bindings);
        }

        if (str_contains($sql, 'delete from')) {
            self::$log[] = 'delete';
        }

        return parent::affectingStatement($query, $bindings);
    }

    /** How many rows a multi row insert carried, from the value groups in its SQL. */
    protected function rows_in(string $query, array $bindings): int
    {
        $values = substr($query, (int) strpos($query, 'values'));

        // the upsert's "on conflict (...)" tail is not a row, do not count its bracket
        if (($conflict = strpos($values, 'on conflict')) !== false) {
            $values = substr($values, 0, $conflict);
        }

        $groups = substr_count($values, '(');

        return $groups > 0 ? $groups : count($bindings);
    }
}
