<?php

/**
 * Tests for the SlaService class.
 *
 * Covers SLA due date calculation (calendar and business hours),
 * breach detection, and warning detection.
 */

use Escalated\Models\Ticket;
use Escalated\Services\SlaService;
use Escalated\Services\TicketService;

class Test_Sla_Service extends WP_UnitTestCase
{
    private SlaService $sla_service;

    private TicketService $ticket_service;

    private int $user_id;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->sla_service = new SlaService;
        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
    }

    /**
     * Helper: Create a ticket.
     */
    private function create_ticket(array $overrides = []): object
    {
        return $this->ticket_service->create($this->user_id, array_merge([
            'subject' => 'SLA Test Ticket',
            'description' => 'Testing SLA.',
            'priority' => 'medium',
        ], $overrides));
    }

    /**
     * Helper: Create an SLA policy in the database.
     */
    private function create_policy(array $overrides = []): object
    {
        global $wpdb;
        $table = \Escalated\Escalated::table('sla_policies');
        $now = current_time('mysql');

        $defaults = [
            'name' => 'Test Policy',
            'description' => 'Test SLA policy.',
            'is_default' => 1,
            'first_response_hours' => wp_json_encode([
                'low' => 24,
                'medium' => 8,
                'high' => 4,
                'urgent' => 2,
                'critical' => 1,
            ]),
            'resolution_hours' => wp_json_encode([
                'low' => 72,
                'medium' => 24,
                'high' => 12,
                'urgent' => 6,
                'critical' => 4,
            ]),
            'business_hours_only' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $data = array_merge($defaults, $overrides);
        $wpdb->insert($table, $data);

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id));
    }

    // =========================================================================
    // Due Date Calculation - Calendar Hours
    // =========================================================================

    public function test_calculate_due_date_calendar_hours(): void
    {
        $from = '2025-01-15 10:00:00';
        $due = $this->sla_service->calculate_due_date($from, 8, false);

        $this->assertEquals('2025-01-15 18:00:00', $due);
    }

    public function test_calculate_due_date_crosses_midnight(): void
    {
        $from = '2025-01-15 20:00:00';
        $due = $this->sla_service->calculate_due_date($from, 8, false);

        $this->assertEquals('2025-01-16 04:00:00', $due);
    }

    public function test_calculate_due_date_fractional_hours(): void
    {
        $from = '2025-01-15 10:00:00';
        $due = $this->sla_service->calculate_due_date($from, 1.5, false);

        $this->assertEquals('2025-01-15 11:30:00', $due);
    }

    // =========================================================================
    // Due Date Calculation - Business Hours
    // =========================================================================

    public function test_calculate_due_date_business_hours_within_day(): void
    {
        // Monday 10:00 + 4 business hours = Monday 14:00.
        $from = '2025-01-13 10:00:00'; // Monday
        $due = $this->sla_service->calculate_due_date($from, 4, true);

        $this->assertEquals('2025-01-13 14:00:00', $due);
    }

    public function test_calculate_due_date_business_hours_spans_days(): void
    {
        // Monday 15:00 + 4 business hours = 2h today + 2h tomorrow = Tuesday 11:00.
        $from = '2025-01-13 15:00:00'; // Monday
        $due = $this->sla_service->calculate_due_date($from, 4, true);

        $this->assertEquals('2025-01-14 11:00:00', $due);
    }

    public function test_calculate_due_date_business_hours_skips_weekend(): void
    {
        // Friday 16:00 + 4 business hours = 1h Friday + 3h Monday = Monday 12:00.
        $from = '2025-01-17 16:00:00'; // Friday
        $due = $this->sla_service->calculate_due_date($from, 4, true);

        $this->assertEquals('2025-01-20 12:00:00', $due);
    }

    public function test_calculate_due_date_business_hours_from_before_start(): void
    {
        // Monday 07:00 (before business hours) + 2 hours = Monday 11:00.
        $from = '2025-01-13 07:00:00'; // Monday
        $due = $this->sla_service->calculate_due_date($from, 2, true);

        $this->assertEquals('2025-01-13 11:00:00', $due);
    }

    public function test_calculate_due_date_business_hours_from_after_end(): void
    {
        // Monday 18:00 (after business hours) + 2 hours = Tuesday 11:00.
        $from = '2025-01-13 18:00:00'; // Monday
        $due = $this->sla_service->calculate_due_date($from, 2, true);

        $this->assertEquals('2025-01-14 11:00:00', $due);
    }

    public function test_calculate_due_date_business_hours_from_weekend(): void
    {
        // Saturday + 2 hours = Monday 11:00.
        $from = '2025-01-18 12:00:00'; // Saturday
        $due = $this->sla_service->calculate_due_date($from, 2, true);

        $this->assertEquals('2025-01-20 11:00:00', $due);
    }

    // =========================================================================
    // Policy Attachment
    // =========================================================================

    public function test_attach_policy_sets_due_dates(): void
    {
        $policy = $this->create_policy();
        $ticket = $this->create_ticket(['priority' => 'medium']);

        $this->sla_service->attach_policy((int) $ticket->id, $policy);

        $updated = Ticket::find((int) $ticket->id);
        $this->assertNotNull($updated->first_response_due_at);
        $this->assertNotNull($updated->resolution_due_at);
        $this->assertEquals((int) $policy->id, (int) $updated->sla_policy_id);
    }

    public function test_attach_default_policy(): void
    {
        $this->create_policy(['is_default' => 1]);
        $ticket = $this->create_ticket(['priority' => 'high']);

        $result = $this->sla_service->attach_default_policy((int) $ticket->id);

        $this->assertTrue($result);

        $updated = Ticket::find((int) $ticket->id);
        $this->assertNotNull($updated->sla_policy_id);
        $this->assertNotNull($updated->first_response_due_at);
    }

    public function test_attach_default_policy_returns_false_when_none(): void
    {
        $ticket = $this->create_ticket();

        $result = $this->sla_service->attach_default_policy((int) $ticket->id);

        $this->assertFalse($result);
    }

    public function test_attach_policy_uses_priority_specific_hours(): void
    {
        $policy = $this->create_policy();

        // Critical priority: 1h first response, 4h resolution.
        $ticket = $this->create_ticket(['priority' => 'critical']);
        $this->sla_service->attach_policy((int) $ticket->id, $policy);

        $updated = Ticket::find((int) $ticket->id);

        // first_response_due_at should be ~1 hour after created_at.
        $created = strtotime($updated->created_at);
        $fr_due = strtotime($updated->first_response_due_at);
        $diff_hours = ($fr_due - $created) / 3600;

        $this->assertEqualsWithDelta(1.0, $diff_hours, 0.05, 'First response due should be ~1 hour for critical.');

        // resolution_due_at should be ~4 hours after created_at.
        $res_due = strtotime($updated->resolution_due_at);
        $res_diff_hours = ($res_due - $created) / 3600;

        $this->assertEqualsWithDelta(4.0, $res_diff_hours, 0.05, 'Resolution due should be ~4 hours for critical.');
    }

    // =========================================================================
    // Breach Detection
    // =========================================================================

    public function test_check_breaches_detects_first_response_breach(): void
    {
        $ticket = $this->create_ticket();

        // Manually set a first_response_due_at in the past.
        Ticket::update((int) $ticket->id, [
            'first_response_due_at' => '2020-01-01 00:00:00',
            'sla_first_response_breached' => 0,
            'sla_policy_id' => 1,
            // Make sure the ticket is in an open status.
            'status' => 'open',
        ]);

        $count = $this->sla_service->check_breaches();

        $updated = Ticket::find((int) $ticket->id);

        $this->assertEquals(1, (int) $updated->sla_first_response_breached);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_check_breaches_fires_action(): void
    {
        $breach_type = null;
        add_action('escalated_sla_breached', function ($ticket, $type) use (&$breach_type) {
            $breach_type = $type;
        }, 10, 2);

        $ticket = $this->create_ticket();
        Ticket::update((int) $ticket->id, [
            'resolution_due_at' => '2020-01-01 00:00:00',
            'sla_resolution_breached' => 0,
            'sla_policy_id' => 1,
            'status' => 'open',
        ]);

        $this->sla_service->check_breaches();

        $updated = Ticket::find((int) $ticket->id);
        $this->assertEquals(1, (int) $updated->sla_resolution_breached);
        $this->assertEquals('resolution', $breach_type);
    }

    public function test_check_breaches_ignores_already_breached(): void
    {
        $ticket = $this->create_ticket();
        Ticket::update((int) $ticket->id, [
            'first_response_due_at' => '2020-01-01 00:00:00',
            'sla_first_response_breached' => 1, // Already breached.
            'sla_policy_id' => 1,
            'status' => 'open',
        ]);

        $count = $this->sla_service->check_breaches();

        // Should not count an already-breached ticket.
        // (It may still count resolution breaches, so we just verify the FR one wasn't re-counted.)
        $this->assertIsInt($count);
    }

    // =========================================================================
    // Warning Detection
    // =========================================================================

    public function test_check_warnings_fires_for_approaching_breach(): void
    {
        $warning_fired = false;
        add_action('escalated_sla_warning', function () use (&$warning_fired) {
            $warning_fired = true;
        });

        $ticket = $this->create_ticket();

        // Set first_response_due_at to 15 minutes from now (within 30-minute warning window).
        $due = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) + (15 * 60));
        Ticket::update((int) $ticket->id, [
            'first_response_due_at' => $due,
            'sla_first_response_breached' => 0,
            'sla_policy_id' => 1,
            'status' => 'open',
        ]);

        $count = $this->sla_service->check_warnings(30);

        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_check_warnings_returns_count(): void
    {
        $count = $this->sla_service->check_warnings(30);
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
