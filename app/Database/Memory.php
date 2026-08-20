<?php

namespace App\Database;

use Symfony\Component\Process\Process;

class Memory
{
    // How much of the host's free memory we are prepared to claim as our ceiling. Half, because the
    // destination MySQL is usually on this same box and much of what the kernel calls available is
    // page cache that MySQL is reading through - take it all and we evict the cache it depends on.
    public const TAKE = 0.5;

    // And never more than this outright. A batch needs select_count x row size x overhead, which is
    // a few hundred MB at most for sane settings, so past here we are only widening what we can lose.
    public const CEILING = 2147483648;

    // Memory we will not touch whatever the sums say, so the host keeps room to work
    public const RESERVE = 536870912;

    // How much of our own ceiling any one batch may account for
    public const BATCH = 0.5;

    // Never size a batch against less than this, however tight things look
    public const FLOOR = 8388608;

    /**
     * Raise the memory limit to a share of what the host actually has free. Batches are held in
     * memory in full, and PHP's default limit is far below what these machines usually have, so
     * this is the difference between paging a table in small pieces and in large ones.
     *
     * Deliberately conservative. MemAvailable is what the kernel thinks it can hand out without
     * swapping, but it counts reclaimable page cache, and on these boxes that cache is largely
     * MySQL's. Claiming it back would avoid swap and still make the sync slower, not faster.
     *
     * Returns the new limit for reporting, or null if it was already as high or we cannot tell.
     */
    public static function raise(): ?string
    {
        $available = self::available();

        if (is_null($available)) {
            return null;
        }

        $target = (int) min(
            $available * self::TAKE,
            self::CEILING,
            max(0, $available - self::RESERVE)
        );

        // Only ever raise. A host too small for this is a host we leave on its own settings.
        if (self::limit() >= $target) {
            return null;
        }

        ini_set('memory_limit', (string) $target);

        return self::format($target);
    }

    /**
     * What one batch is allowed to account for.
     */
    public static function budget(): int
    {
        $limit = self::limit();

        // With no limit set at all, go by what the host says is free rather than assuming the world
        if ($limit === PHP_INT_MAX) {
            $limit = self::available() ?? (512 * 1024 * 1024);
        }

        return (int) max(self::FLOOR, ($limit - memory_get_usage(true)) * self::BATCH);
    }

    /**
     * The current limit in bytes. An unlimited setting reads as the largest integer we have.
     */
    public static function limit(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || (int) $limit < 0) {
            return PHP_INT_MAX;
        }

        return match (strtolower(substr($limit, -1))) {
            'g' => (int) $limit * 1073741824,
            'm' => (int) $limit * 1048576,
            'k' => (int) $limit * 1024,
            default => (int) $limit,
        };
    }

    /**
     * Memory the host has free right now, or null where we have no reliable way to ask.
     */
    public static function available(): ?int
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => self::available_linux(),
            'Darwin' => self::available_darwin(),
            default => null,
        };
    }

    protected static function available_linux(): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        // MemAvailable is the kernel's own estimate of what can be handed out without swapping,
        // which is a much better guide than MemFree once the page cache has filled up
        if (preg_match('/^MemAvailable:\s+(\d+) kB/m', (string) file_get_contents('/proc/meminfo'), $found)) {
            return (int) $found[1] * 1024;
        }

        return null;
    }

    protected static function available_darwin(): ?int
    {
        try {
            $process = new Process(['vm_stat']);
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        if (! preg_match('/page size of (\d+) bytes/', $output, $size)) {
            return null;
        }

        $pages = 0;

        foreach (['Pages free', 'Pages inactive', 'Pages speculative'] as $kind) {
            if (preg_match('/'.preg_quote($kind, '/').':\s+(\d+)\./', $output, $found)) {
                $pages += (int) $found[1];
            }
        }

        return $pages > 0 ? $pages * (int) $size[1] : null;
    }

    public static function format(int $bytes): string
    {
        foreach (['G' => 1073741824, 'M' => 1048576, 'K' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1).$unit;
            }
        }

        return $bytes.'B';
    }
}
