<?php

namespace Tests\Unit;

use App\Database\Backup;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EtaTest extends TestCase
{
    protected ReflectionClass $reflect;

    protected Backup $backup;

    protected function setUp(): void
    {
        $this->reflect = new ReflectionClass(Backup::class);
        $this->backup = $this->reflect->newInstanceWithoutConstructor();
    }

    protected function duration(int $seconds): string
    {
        $method = $this->reflect->getMethod('duration');
        $method->setAccessible(true);

        return $method->invoke(null, $seconds);
    }

    /** Record a pass of $rows rows that took $seconds, without actually waiting. */
    protected function pass(int $rows, float $seconds): void
    {
        $marked = $this->reflect->getProperty('marked');
        $marked->setAccessible(true);
        $marked->setValue($this->backup, microtime(true) - $seconds);

        $method = $this->reflect->getMethod('sample');
        $method->setAccessible(true);
        $method->invoke($this->backup, $rows, microtime(true) - $seconds);
    }

    protected function eta(float $elapsed, int $done, int $total): ?string
    {
        $method = $this->reflect->getMethod('eta');
        $method->setAccessible(true);

        return $method->invoke($this->backup, microtime(true) - $elapsed, $done, $total);
    }

    public function test_it_formats_durations(): void
    {
        $this->assertSame('45s', $this->duration(45));
        $this->assertSame('1m 30s', $this->duration(90));
        $this->assertSame('2h 15m', $this->duration(8100));
        $this->assertSame('1d 1h', $this->duration(90000));
    }

    public function test_it_says_nothing_before_the_first_twenty_seconds(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass(55000, 4.0);
        }

        $this->assertNull($this->eta(19.0, 275000, 409336022));
    }

    public function test_it_says_nothing_until_a_few_passes_have_been_seen(): void
    {
        $this->pass(55000, 10.0);

        $this->assertNull($this->eta(25.0, 55000, 409336022));
    }

    public function test_it_speaks_up_by_thirty_seconds_however_slow_the_passes(): void
    {
        $this->pass(2500, 30.0);

        $this->assertNotNull($this->eta(31.0, 2500, 409336022));
    }

    public function test_a_steady_rate_gives_a_steady_answer(): void
    {
        $answers = [];

        for ($i = 1; $i <= 8; $i++) {
            $this->pass(55000, 8.0);
            $answers[] = $this->eta(8.0 * $i, 55000 * $i, 409336022);
        }

        // the first two are held back, the rest must agree with each other
        $settled = array_unique(array_filter(array_slice($answers, 2)));

        $this->assertCount(1, $settled, 'a steady rate should not make the estimate wander');
    }

    public function test_it_follows_a_slowdown_rather_than_averaging_it_away(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass(55000, 8.0);
        }

        $before = $this->eta(40.0, 275000, 409336022);

        // the window is five passes, so five slow ones replace it entirely
        for ($i = 0; $i < 5; $i++) {
            $this->pass(55000, 16.0);
        }

        $after = $this->eta(120.0, 550000, 409336022);

        $this->assertNotSame($before, $after);
        $this->assertSame('About 1d 9h remaining', $after);
        $this->assertSame('About 16h 31m remaining', $before);
    }

    public function test_it_says_nothing_once_the_total_is_reached(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pass(55000, 8.0);
        }

        $this->assertNull($this->eta(40.0, 409336022, 409336022));
    }
}
