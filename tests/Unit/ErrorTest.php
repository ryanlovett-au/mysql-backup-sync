<?php

namespace Tests\Unit;

use App\Database\Error;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorTest extends TestCase
{
    protected function pdo(int $code, string $message): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = ['HY000', $code, $message];

        return $e;
    }

    public function test_it_reads_the_driver_code(): void
    {
        $this->assertSame(2006, Error::code($this->pdo(2006, 'MySQL server has gone away')));
    }

    public function test_it_reads_the_code_through_a_query_exception(): void
    {
        $wrapped = new QueryException('host_1', 'select 1', [], $this->pdo(1040, 'Too many connections'));

        $this->assertSame(1040, Error::code($wrapped));
    }

    public function test_it_has_no_code_for_a_plain_exception(): void
    {
        $this->assertNull(Error::code(new RuntimeException('Tunnel never came up')));
    }

    public function test_it_explains_a_code_it_knows(): void
    {
        $this->assertSame(
            '1040 - Too many connections, the server has reached max_connections and cannot take another.',
            Error::describe($this->pdo(1040, 'Too many connections'))
        );
    }

    public function test_it_falls_back_to_the_driver_message_for_a_code_it_does_not_know(): void
    {
        $this->assertSame('1370 - execute command denied', Error::describe($this->pdo(1370, 'execute command denied')));
    }

    public function test_it_reports_the_message_when_there_is_no_code(): void
    {
        $this->assertSame('Tunnel never came up', Error::describe(new RuntimeException('Tunnel never came up')));
    }

    public function test_it_does_not_dump_the_whole_query_for_a_wrapped_error(): void
    {
        $wrapped = new QueryException('host_1', 'select * from orders where id > ?', [5], $this->pdo(2006, 'MySQL server has gone away'));

        $this->assertStringNotContainsString('select *', Error::describe($wrapped));
    }
}
