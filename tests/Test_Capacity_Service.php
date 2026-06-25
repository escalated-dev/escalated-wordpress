<?php

/**
 * Tests for agent capacity tracking.
 *
 * Pure-function tests for has_capacity / load_percentage; the
 * can_accept_ticket / increment / decrement flow is exercised via a live
 * wpdb (the test suite's mysql harness).
 */

use Escalated\Models\AgentCapacity;
use Escalated\Services\CapacityService;

class Test_Capacity_Service extends WP_UnitTestCase
{
    // ---------------------------------------------------------------------
    // has_capacity (pure)
    // ---------------------------------------------------------------------

    public function test_has_capacity_when_below_ceiling()
    {
        $this->assertTrue(AgentCapacity::has_capacity(2, 3));
    }

    public function test_no_capacity_when_at_or_over_ceiling()
    {
        $this->assertFalse(AgentCapacity::has_capacity(3, 3));
        $this->assertFalse(AgentCapacity::has_capacity(4, 3));
    }

    // ---------------------------------------------------------------------
    // load_percentage (pure)
    // ---------------------------------------------------------------------

    public function test_load_percentage()
    {
        $this->assertEquals(30.0, AgentCapacity::load_percentage(3, 10));
        $this->assertEquals(25.0, AgentCapacity::load_percentage(2, 8));
    }

    public function test_load_percentage_zero_ceiling_is_full()
    {
        $this->assertEquals(100.0, AgentCapacity::load_percentage(0, 0));
    }

    // ---------------------------------------------------------------------
    // Service flow (live wpdb)
    // ---------------------------------------------------------------------

    public function test_can_accept_increment_and_decrement()
    {
        $service = new CapacityService;
        $user_id = 4242;

        // Fresh agent (default ceiling 10) has capacity.
        $this->assertTrue($service->can_accept_ticket($user_id));

        // Fill to the ceiling.
        for ($i = 0; $i < 10; $i++) {
            $service->increment_load($user_id);
        }
        $this->assertFalse($service->can_accept_ticket($user_id));

        // Releasing one frees capacity again.
        $service->decrement_load($user_id);
        $this->assertTrue($service->can_accept_ticket($user_id));
    }

    public function test_decrement_never_goes_negative()
    {
        $service = new CapacityService;
        $user_id = 4343;

        // Decrementing a fresh (zero) row keeps it at zero.
        $service->decrement_load($user_id);

        $row = AgentCapacity::for_user($user_id);
        $this->assertNotNull($row);
        $this->assertEquals(0, (int) $row->current_count);
    }
}
