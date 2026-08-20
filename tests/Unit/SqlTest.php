<?php

namespace Tests\Unit;

use App\Database\Sql;
use PHPUnit\Framework\TestCase;

class SqlTest extends TestCase
{
    public function test_it_wraps_an_identifier_in_backticks(): void
    {
        $this->assertSame('`audit_log`', Sql::quote('audit_log'));
    }

    public function test_it_survives_a_reserved_word(): void
    {
        $this->assertSame('`order`', Sql::quote('order'));
    }

    public function test_it_survives_a_hyphen(): void
    {
        $this->assertSame('`backup_db-01_app`', Sql::quote('backup_db-01_app'));
    }

    public function test_it_doubles_an_embedded_backtick(): void
    {
        $this->assertSame('`my``table`', Sql::quote('my`table'));
    }
}
