<?php

namespace Tests\Feature;

use Tests\Support\RoutingBackup;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    protected function route(array $table, array $state): array
    {
        $backup = new RoutingBackup;
        $backup->table = (object) array_merge(['table_name' => 'orders', 'is_active' => 1, 'always_resync' => 0], $table);
        $backup->state = (object) array_merge(['last_id' => null, 'last_updated_at' => null], $state);

        $backup->action(true);

        return $backup->called;
    }

    public function test_a_table_with_a_position_carries_on_from_it(): void
    {
        $this->assertSame(['update'], $this->route([], ['last_id' => 5000]));
    }

    public function test_a_table_with_no_position_starts_again(): void
    {
        $this->assertSame(['resync'], $this->route([], ['last_id' => null]));
    }

    public function test_a_position_of_zero_is_no_position(): void
    {
        $this->assertSame(['resync'], $this->route([], ['last_id' => 0]));
    }

    public function test_always_resync_ignores_the_position(): void
    {
        $this->assertSame(['resync'], $this->route(['always_resync' => 1], ['last_id' => 5000]));
    }

    /**
     * A timestamp alone must not route to update(). Those are tables with no single column key,
     * where an upsert has nothing to match on and would append the whole table again.
     */
    public function test_a_timestamp_without_a_position_still_starts_again(): void
    {
        $this->assertSame(['resync'], $this->route([], ['last_updated_at' => '2025-06-01 09:00:00']));
    }

    public function test_an_inactive_table_is_left_alone(): void
    {
        $this->assertSame([], $this->route(['is_active' => 0], ['last_id' => 5000]));
    }
}
