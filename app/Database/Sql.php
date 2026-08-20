<?php

namespace App\Database;

class Sql
{
    /**
     * Quote an identifier for MySQL. The query builder does this for us everywhere it can, but a
     * handful of statements have to be built by hand, and a database name with a hyphen in it or a
     * table named after a reserved word will break those without it.
     */
    public static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
