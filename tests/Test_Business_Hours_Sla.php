<?php

/**
 * SLA deadlines in business time: which schedule a ticket is counted against
 * (policy, then department, then the default), and that an install with no
 * schedule keeps the fixed 9-17 filter behaviour.
 */

use Escalated\Activator;
use Escalated\Escalated;
use Escalated\Models\BusinessSchedule;
use Escalated\Models\Department;
use Escalated\Models\Ticket;
use Escalated\Services\SlaService;
use Escalated\Services\TicketService;

class Test_Business_Hours_Sla extends WP_UnitTestCase
{
    private const NINE_TO_FIVE = ['start' => '09:00', 'end' => '17:00'];

    private const CLOSED = ['start' => '', 'end' => ''];

    /** 2026-10-19 is a Monday. */
    private const MONDAY_NOON = '2026-10-19 12:00:00';

    private int $user_id;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        update_option('timezone_string', 'UTC');
    }

    public function tear_down(): void
    {
        update_option('timezone_string', 'UTC');
        parent::tear_down();
    }

    public function test_the_upgrade_adds_the_tables_and_the_reference_columns(): void
    {
        $wpdb = Escalated::db();

        $this->assertSame(Escalated::table('business_schedules'), $wpdb->get_var("SHOW TABLES LIKE '".Escalated::table('business_schedules')."'"));
        $this->assertSame(Escalated::table('holidays'), $wpdb->get_var("SHOW TABLES LIKE '".Escalated::table('holidays')."'"));
        foreach (['sla_policies', 'departments'] as $table) {
            $column = $wpdb->get_row('SHOW COLUMNS FROM '.Escalated::table($table)." LIKE 'business_schedule_id'");
            $this->assertNotNull($column, $table.' has no business_schedule_id');
            $this->assertSame('YES', $column->Null);
        }
    }

    public function test_without_a_schedule_business_hours_keep_the_fixed_filter_behaviour(): void
    {
        $policy = $this->policy(['business_hours_only' => 1]);
        $ticket = $this->ticket();

        (new SlaService)->attach_policy($ticket, $policy);

        $legacy = (new SlaService)->calculate_due_date(self::MONDAY_NOON, 2, true);
        $this->assertSame($legacy, Ticket::find($ticket)->first_response_due_at);
        $this->assertSame('2026-10-19 14:00:00', Ticket::find($ticket)->first_response_due_at);
        $this->assertSame('2026-10-19 16:00:00', Ticket::find($ticket)->resolution_due_at);
    }

    public function test_the_default_schedule_is_used_when_nothing_more_specific_is_set(): void
    {
        $this->schedule('Toronto', 'America/Toronto', true);
        $policy = $this->policy(['business_hours_only' => 1]);
        $ticket = $this->ticket();

        (new SlaService)->attach_policy($ticket, $policy);

        // 12:00Z is 08:00 in Toronto: the clock starts at 09:00 (13:00Z).
        $this->assertSame('2026-10-19 15:00:00', Ticket::find($ticket)->first_response_due_at);
        $this->assertSame('2026-10-19 17:00:00', Ticket::find($ticket)->resolution_due_at);
    }

    public function test_a_department_schedule_beats_the_default(): void
    {
        $this->schedule('Toronto', 'America/Toronto', true);
        $tokyo = $this->schedule('Tokyo', 'Asia/Tokyo');
        $department = (int) Department::create(['name' => 'Asia', 'slug' => 'asia', 'is_active' => 1, 'business_schedule_id' => $tokyo]);
        $policy = $this->policy(['business_hours_only' => 1]);
        $ticket = $this->ticket(['department_id' => $department]);

        (new SlaService)->attach_policy($ticket, $policy);

        // Monday 12:00Z is 21:00 in Tokyo: next opening is Tuesday 09:00 JST (00:00Z).
        $this->assertSame('2026-10-20 02:00:00', Ticket::find($ticket)->first_response_due_at);
    }

    public function test_a_policy_schedule_beats_the_department_and_the_default(): void
    {
        $this->schedule('Toronto', 'America/Toronto', true);
        $tokyo = $this->schedule('Tokyo', 'Asia/Tokyo');
        $utc = $this->schedule('Late UTC', 'UTC', false, ['monday' => ['start' => '13:00', 'end' => '24:00']]);
        $department = (int) Department::create(['name' => 'Asia', 'slug' => 'asia', 'is_active' => 1, 'business_schedule_id' => $tokyo]);
        $policy = $this->policy(['business_hours_only' => 1, 'business_schedule_id' => $utc]);
        $ticket = $this->ticket(['department_id' => $department]);

        (new SlaService)->attach_policy($ticket, $policy);

        $this->assertSame('2026-10-19 15:00:00', Ticket::find($ticket)->first_response_due_at);
    }

    public function test_a_policy_not_limited_to_business_hours_ignores_schedules(): void
    {
        $this->schedule('Toronto', 'America/Toronto', true);
        $policy = $this->policy(['business_hours_only' => 0]);
        $ticket = $this->ticket();

        (new SlaService)->attach_policy($ticket, $policy);

        $this->assertSame('2026-10-19 14:00:00', Ticket::find($ticket)->first_response_due_at);
    }

    public function test_holidays_on_the_schedule_are_skipped(): void
    {
        $this->schedule('UTC', 'UTC', true, null, [['name' => 'Closed', 'date' => '2026-10-19', 'recurring' => 0]]);
        $policy = $this->policy(['business_hours_only' => 1]);
        $ticket = $this->ticket();

        (new SlaService)->attach_policy($ticket, $policy);

        $this->assertSame('2026-10-20 11:00:00', Ticket::find($ticket)->first_response_due_at);
    }

    public function test_deadlines_are_stored_in_the_site_time_zone(): void
    {
        update_option('timezone_string', 'America/Toronto');
        $this->schedule('Toronto', 'America/Toronto', true);
        $policy = $this->policy(['business_hours_only' => 1]);
        // Stored times are site-local: 08:00 Toronto.
        $ticket = $this->ticket([], '2026-10-19 08:00:00');

        (new SlaService)->attach_policy($ticket, $policy);

        $this->assertSame('2026-10-19 11:00:00', Ticket::find($ticket)->first_response_due_at);
    }

    public function test_the_default_policy_is_counted_on_the_default_schedule(): void
    {
        $this->schedule('UTC', 'UTC', true, ['monday' => self::CLOSED, 'tuesday' => self::CLOSED, 'wednesday' => self::CLOSED, 'thursday' => self::CLOSED, 'friday' => self::CLOSED, 'saturday' => self::NINE_TO_FIVE, 'sunday' => self::CLOSED]);
        $this->policy(['business_hours_only' => 1, 'is_default' => 1]);
        $id = $this->ticket();

        $this->assertTrue((new SlaService)->attach_default_policy($id));

        // Monday noon; only Saturdays are open.
        $this->assertSame('2026-10-24 11:00:00', Ticket::find($id)->first_response_due_at);
    }

    public function test_deleting_a_schedule_clears_the_references_to_it(): void
    {
        $id = $this->schedule('Gone', 'UTC');
        $department = (int) Department::create(['name' => 'D', 'slug' => 'd', 'is_active' => 1, 'business_schedule_id' => $id]);
        $policy = $this->policy(['business_schedule_id' => $id]);

        BusinessSchedule::delete($id);

        $this->assertNull(BusinessSchedule::find($id));
        $this->assertSame([], BusinessSchedule::holidays($id));
        $this->assertNull(Department::find($department)->business_schedule_id);
        $this->assertNull(\Escalated\Models\SlaPolicy::find($policy->id)->business_schedule_id);
    }

    public function test_a_form_only_changes_the_reference_when_it_sends_one(): void
    {
        $id = $this->schedule('Real', 'UTC');

        $this->assertSame([], BusinessSchedule::reference_from_input(['name' => 'x']));
        $this->assertSame(['business_schedule_id' => null], BusinessSchedule::reference_from_input(['business_schedule_id' => '']));
        $this->assertSame(['business_schedule_id' => null], BusinessSchedule::reference_from_input(['business_schedule_id' => '999999']));
        $this->assertSame(['business_schedule_id' => $id], BusinessSchedule::reference_from_input(['business_schedule_id' => (string) $id]));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function schedule(string $name, string $timezone, bool $default = false, ?array $hours = null, array $holidays = []): int
    {
        return (int) BusinessSchedule::create([
            'name' => $name,
            'timezone' => $timezone,
            'is_default' => $default,
            'schedule' => $hours ?? [
                'monday' => self::NINE_TO_FIVE,
                'tuesday' => self::NINE_TO_FIVE,
                'wednesday' => self::NINE_TO_FIVE,
                'thursday' => self::NINE_TO_FIVE,
                'friday' => self::NINE_TO_FIVE,
                'saturday' => self::CLOSED,
                'sunday' => self::CLOSED,
            ],
            'holidays' => $holidays,
        ]);
    }

    private function policy(array $overrides = []): object
    {
        $id = \Escalated\Models\SlaPolicy::create(array_merge([
            'name' => 'Business',
            'is_default' => 0,
            'first_response_hours' => ['medium' => 2],
            'resolution_hours' => ['medium' => 4],
            'business_hours_only' => 0,
            'is_active' => 1,
        ], $overrides));

        return \Escalated\Models\SlaPolicy::find($id);
    }

    private function ticket(array $overrides = [], string $created_at = self::MONDAY_NOON): int
    {
        $ticket = (new TicketService)->create($this->user_id, array_merge([
            'subject' => 'Business hours',
            'description' => 'Counting.',
            'priority' => 'medium',
        ], $overrides));
        Ticket::update((int) $ticket->id, ['created_at' => $created_at]);

        return (int) $ticket->id;
    }
}
