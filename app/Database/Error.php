<?php

namespace App\Database;

use PDOException;
use Throwable;

class Error
{
    /**
     * Plain english for the errors this application actually runs into, so a failure reads as
     * something you can act on rather than a bare number.
     */
    public const EXPLANATIONS = [
        1040 => 'Too many connections, the server has reached max_connections and cannot take another.',
        1044 => 'Access denied to that database for this user.',
        1045 => 'Access denied, check the username and password.',
        1049 => 'That database does not exist on the server.',
        1062 => 'Duplicate entry for a key that has to be unique.',
        1114 => 'The table is full.',
        1142 => 'This user is not allowed to run that command against that table.',
        1146 => 'That table does not exist.',
        1153 => 'A packet was larger than max_allowed_packet, reduce the update count in the local config.',
        1205 => 'Timed out waiting on a row lock held by another transaction.',
        1213 => 'Deadlock, the transaction was rolled back and the run can be retried.',
        1226 => 'This user has gone over a resource limit set on the server.',
        1390 => 'Too many placeholders in one statement, reduce the update count in the local config.',
        2002 => 'Could not connect to the server, check the host and port, or the SSH tunnel.',
        2003 => 'Could not reach the server, check the host and port, or the SSH tunnel.',
        2005 => 'That server hostname could not be resolved.',
        2006 => 'The server went away, usually out of memory or a timeout sorting a large result.',
        2013 => 'Lost the connection to the server while the query was running.',
    ];

    /**
     * The driver's own error detail. Laravel wraps PDO errors in a QueryException and copies this
     * across, but plenty of failures are not PDO errors at all and carry nothing.
     */
    protected static function info(Throwable $e): ?array
    {
        foreach ([$e, $e->getPrevious()] as $candidate) {
            if ($candidate instanceof PDOException && is_array($candidate->errorInfo)) {
                return $candidate->errorInfo;
            }
        }

        return null;
    }

    public static function code(Throwable $e): int|string|null
    {
        return self::info($e)[1] ?? null;
    }

    /**
     * Something worth showing the user. The exception message itself carries the entire query and
     * its bindings, so prefer the explanation, then the driver's short message.
     */
    public static function describe(Throwable $e): string
    {
        $code = self::code($e);

        if (is_null($code)) {
            return $e->getMessage();
        }

        $explanation = self::EXPLANATIONS[$code]
            ?? self::info($e)[2]
            ?? 'no further detail given';

        return $code.' - '.$explanation;
    }
}
