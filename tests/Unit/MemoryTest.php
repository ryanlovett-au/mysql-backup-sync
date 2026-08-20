<?php

namespace Tests\Unit;

use App\Database\Memory;
use PHPUnit\Framework\TestCase;

class MemoryTest extends TestCase
{
    protected string $original;

    protected function setUp(): void
    {
        $this->original = ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->original);
    }

    public function test_it_reads_the_shorthand_limit(): void
    {
        ini_set('memory_limit', '128M');
        $this->assertSame(134217728, Memory::limit());

        ini_set('memory_limit', '2G');
        $this->assertSame(2147483648, Memory::limit());
    }

    public function test_an_unlimited_setting_reads_as_the_largest_integer(): void
    {
        ini_set('memory_limit', '-1');
        $this->assertSame(PHP_INT_MAX, Memory::limit());
    }

    public function test_it_never_lowers_an_already_generous_limit(): void
    {
        ini_set('memory_limit', '8G');

        $this->assertNull(Memory::raise(), 'raise() should leave a limit that is already high alone');
        $this->assertSame(8589934592, Memory::limit());
    }

    public function test_a_batch_never_gets_the_whole_limit(): void
    {
        ini_set('memory_limit', '2G');

        $this->assertLessThan(Memory::limit(), Memory::budget());
    }

    public function test_a_batch_budget_never_falls_below_the_floor(): void
    {
        ini_set('memory_limit', '16M');

        $this->assertSame(Memory::FLOOR, Memory::budget());
    }

    public function test_it_formats_sizes_readably(): void
    {
        $this->assertSame('2G', Memory::format(2147483648));
        $this->assertSame('512M', Memory::format(536870912));
        $this->assertSame('1.5K', Memory::format(1536));
    }
}
