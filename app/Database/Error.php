<?php

namespace App\Database;

use PDOException;
use Throwable;

class Error
{
    /**
     * Pull the driver error code out of an exception. Laravel wraps PDO errors in a QueryException
     * and copies errorInfo across, but plenty of failures are not PDO errors at all and carry no
     * code, in which case reading errorInfo just warns and hands back nothing useful.
     */
    public static function code(Throwable $e): int|string|null
    {
        if ($e instanceof PDOException && isset($e->errorInfo[1])) {
            return $e->errorInfo[1];
        }

        $previous = $e->getPrevious();

        if ($previous instanceof PDOException && isset($previous->errorInfo[1])) {
            return $previous->errorInfo[1];
        }

        return null;
    }

    /**
     * Something worth showing the user - the driver code where there is one, the message where
     * there is not, so a non database failure no longer reports itself as an empty error.
     */
    public static function describe(Throwable $e): string
    {
        return (string) (self::code($e) ?? $e->getMessage());
    }
}
