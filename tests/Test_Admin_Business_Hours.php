<?php

/**
 * Escalated > Business Hours (wp-admin): schedule and holiday CRUD, and the
 * schedule pickers on the SLA policy and department screens.
 */

use Escalated\Activator;
use Escalated\Admin\Admin_Business_Hours;
use Escalated\Admin\Admin_Departments;
use Escalated\Admin\Admin_Sla_Policies;
use Escalated\Models\BusinessSchedule;
use Escalated\Models\Department;
use Escalated\Models\SlaPolicy;

class Test_Admin_Business_Hours extends WP_UnitTestCase
{
    private int $admin_id;

    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        $_GET = [];
        $_POST = [];
    }

    public function tear_down(): void
    {
        $_GET = [];
        $_POST = [];
        remove_all_filters('wp_redirect');
        parent::tear_down();
    }

    public function test_an_admin_creates_a_schedule_with_hours_and_holidays(): void
    {
        $redirect = (new Admin_Business_Hours)->process($this->form('create', [
            'holidays' => [
                ['name' => 'Christmas', 'date' => '2026-12-25', 'recurring' => '1'],
                ['name' => '', 'date' => ''],
            ],
        ]));

        $this->assertStringContainsString('message=created', $redirect);
        $rows = BusinessSchedule::all();
        $this->assertCount(1, $rows);
        $this->assertSame('Head office', $rows[0]->name);
        $this->assertSame('America/Toronto', $rows[0]->timezone);
        $this->assertSame('1', (string) $rows[0]->is_default);
        $hours = BusinessSchedule::hours($rows[0]);
        $this->assertSame(['start' => '09:00', 'end' => '17:00'], $hours['monday']);
        $this->assertSame(['start' => '', 'end' => ''], $hours['sunday']);
        $this->assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], array_keys($hours));

        $holidays = BusinessSchedule::holidays((int) $rows[0]->id);
        $this->assertCount(1, $holidays);
        $this->assertSame('Christmas', $holidays[0]->name);
        $this->assertSame('2026-12-25', $holidays[0]->date);
        $this->assertSame('1', (string) $holidays[0]->recurring);
    }

    public function test_marking_a_schedule_default_unmarks_the_others(): void
    {
        (new Admin_Business_Hours)->process($this->form('create', ['name' => 'First']));
        (new Admin_Business_Hours)->process($this->form('create', ['name' => 'Second']));

        $defaults = array_values(array_filter(BusinessSchedule::all(), static fn ($s) => (int) $s->is_default === 1));
        $this->assertCount(1, $defaults);
        $this->assertSame('Second', $defaults[0]->name);
    }

    /**
     * @dataProvider invalid_forms
     */
    public function test_an_invalid_form_saves_nothing(array $overrides, string $message): void
    {
        $redirect = (new Admin_Business_Hours)->process($this->form('create', $overrides));

        $this->assertStringContainsString('message='.$message, $redirect);
        $this->assertSame([], BusinessSchedule::all());
    }

    public function invalid_forms(): array
    {
        return [
            'no name' => [['name' => '  '], 'missing_name'],
            'long name' => [['name' => str_repeat('a', 256)], 'missing_name'],
            'unknown time zone' => [['timezone' => 'Mars/Olympus'], 'invalid_timezone'],
            'bad time' => [['schedule' => ['monday' => ['start' => '25:00', 'end' => '17:00']]], 'invalid_hours'],
            'half a day' => [['schedule' => ['monday' => ['start' => '09:00', 'end' => '']]], 'invalid_hours'],
            'holiday without a date' => [['holidays' => [['name' => 'Party', 'date' => '']]], 'invalid_holiday'],
            'holiday with a bad date' => [['holidays' => [['name' => 'Party', 'date' => '2026-02-30']]], 'invalid_holiday'],
            'holiday without a name' => [['holidays' => [['name' => '', 'date' => '2026-02-03']]], 'invalid_holiday'],
        ];
    }

    public function test_an_update_replaces_the_holidays(): void
    {
        $id = (int) BusinessSchedule::create([
            'name' => 'Old',
            'timezone' => 'UTC',
            'schedule' => ['monday' => ['start' => '09:00', 'end' => '17:00']],
            'holidays' => [['name' => 'Gone', 'date' => '2026-01-01'], ['name' => 'Kept', 'date' => '2026-07-01']],
        ]);

        $redirect = (new Admin_Business_Hours)->process($this->form('update', [
            'id' => (string) $id,
            'name' => 'Renamed',
            'is_default' => null,
            'holidays' => [
                ['name' => 'Gone', 'date' => '2026-01-01', 'remove' => '1'],
                ['name' => 'Kept', 'date' => '2026-07-01'],
                ['name' => 'New', 'date' => '2026-08-03'],
            ],
        ], $id));

        $this->assertStringContainsString('message=updated', $redirect);
        $row = BusinessSchedule::find($id);
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('0', (string) $row->is_default);
        $this->assertSame(['Kept', 'New'], array_map(static fn ($h) => $h->name, BusinessSchedule::holidays($id)));
    }

    public function test_an_admin_deletes_a_schedule(): void
    {
        $id = (int) BusinessSchedule::create(['name' => 'Gone', 'timezone' => 'UTC', 'schedule' => []]);

        $redirect = (new Admin_Business_Hours)->process([
            'escalated_business_hours_action' => 'delete',
            'id' => (string) $id,
            '_escalated_nonce' => wp_create_nonce('escalated_business_hours_delete_'.$id),
        ]);

        $this->assertStringContainsString('message=deleted', $redirect);
        $this->assertNull(BusinessSchedule::find($id));
    }

    public function test_the_form_needs_a_valid_nonce(): void
    {
        $this->expectException(WPDieException::class);

        (new Admin_Business_Hours)->process(array_merge($this->form('create'), ['_escalated_nonce' => 'forged']));
    }

    public function test_an_agent_cannot_change_schedules(): void
    {
        wp_set_current_user($this->factory->user->create(['role' => 'escalated_agent']));
        $this->expectException(WPDieException::class);

        (new Admin_Business_Hours)->process($this->form('create'));
    }

    public function test_the_screen_lists_schedules_and_edits_one(): void
    {
        $id = (int) BusinessSchedule::create([
            'name' => 'Listed <b>office</b>',
            'timezone' => 'Asia/Tokyo',
            'is_default' => true,
            'schedule' => ['monday' => ['start' => '08:30', 'end' => '18:00']],
            'holidays' => [['name' => 'Founders day', 'date' => '2026-04-01', 'recurring' => 1]],
        ]);

        ob_start();
        (new Admin_Business_Hours)->render();
        $list = ob_get_clean();

        $this->assertStringContainsString('Listed &lt;b&gt;office&lt;/b&gt;', $list);
        $this->assertStringContainsString('Asia/Tokyo', $list);
        $this->assertStringContainsString('action=edit&#038;id='.$id, $list);
        $this->assertStringContainsString('name="escalated_business_hours_action" value="delete"', $list);
        $this->assertStringContainsString('<option value="Europe/Paris"', $list);
        $this->assertStringContainsString('name="schedule[monday][start]"', $list);

        $_GET = ['action' => 'edit', 'id' => (string) $id];
        ob_start();
        (new Admin_Business_Hours)->render();
        $form = ob_get_clean();

        $this->assertStringContainsString('name="escalated_business_hours_action" value="update"', $form);
        $this->assertStringContainsString('<option value="Asia/Tokyo" selected', $form);
        $this->assertStringContainsString('<option value="08:30" selected', $form);
        $this->assertStringContainsString('value="Founders day"', $form);
        $this->assertStringContainsString('value="2026-04-01"', $form);
    }

    public function test_the_sla_policy_screen_saves_the_chosen_schedule(): void
    {
        $schedule = (int) BusinessSchedule::create(['name' => 'Night desk', 'timezone' => 'UTC', 'schedule' => []]);
        $policy = (int) SlaPolicy::create(['name' => 'P', 'first_response_hours' => [], 'resolution_hours' => [], 'is_active' => 1]);

        ob_start();
        (new Admin_Sla_Policies)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString('name="business_schedule_id"', $html);
        $this->assertStringContainsString('Night desk', $html);

        $this->post([
            'escalated_sla_action' => 'update',
            'id' => (string) $policy,
            '_escalated_nonce' => wp_create_nonce('escalated_sla_update_'.$policy),
            'name' => 'P',
            'business_hours_only' => '1',
            'is_active' => '1',
            'business_schedule_id' => (string) $schedule,
        ], [new Admin_Sla_Policies, 'handle_actions']);

        $this->assertSame((string) $schedule, (string) SlaPolicy::find($policy)->business_schedule_id);
    }

    public function test_the_department_screen_saves_the_chosen_schedule(): void
    {
        $schedule = (int) BusinessSchedule::create(['name' => 'Night desk', 'timezone' => 'UTC', 'schedule' => []]);
        $department = (int) Department::create(['name' => 'Support', 'slug' => 'support', 'is_active' => 1, 'business_schedule_id' => $schedule]);

        $_GET = ['action' => 'edit', 'id' => (string) $department];
        ob_start();
        (new Admin_Departments)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString('name="business_schedule_id"', $html);
        $this->assertStringContainsString('<option value="'.$schedule.'" selected', $html);

        $this->post([
            'escalated_department_action' => 'update',
            'id' => (string) $department,
            '_escalated_nonce' => wp_create_nonce('escalated_department_update_'.$department),
            'name' => 'Support',
            'slug' => 'support',
            'is_active' => '1',
            'business_schedule_id' => '',
        ], [new Admin_Departments, 'handle_actions']);

        $this->assertNull(Department::find($department)->business_schedule_id);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function form(string $action, array $overrides = [], int $id = 0): array
    {
        $form = array_merge([
            'escalated_business_hours_action' => $action,
            '_escalated_nonce' => wp_create_nonce($action === 'create' ? 'escalated_business_hours_create' : 'escalated_business_hours_'.$action.'_'.$id),
            'name' => 'Head office',
            'timezone' => 'America/Toronto',
            'is_default' => '1',
            'schedule' => [
                'monday' => ['start' => '09:00', 'end' => '17:00'],
                'tuesday' => ['start' => '09:00', 'end' => '17:00'],
                'wednesday' => ['start' => '09:00', 'end' => '17:00'],
                'thursday' => ['start' => '09:00', 'end' => '17:00'],
                'friday' => ['start' => '09:00', 'end' => '17:00'],
                'saturday' => ['start' => '', 'end' => ''],
                'sunday' => ['start' => '', 'end' => ''],
            ],
            'holidays' => [],
        ], $overrides);

        return array_filter($form, static fn ($v) => $v !== null);
    }

    /**
     * Run a screen's POST handler the way admin_init does, stopping at its
     * redirect instead of the exit after it.
     */
    private function post(array $post, callable $handler): void
    {
        $_POST = wp_slash($post);
        add_filter('wp_redirect', static function ($location) {
            throw new RuntimeException('redirect:'.$location);
        });

        try {
            $handler();
            $this->fail('the handler did not redirect');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('redirect:', $e->getMessage());
        } finally {
            $_POST = [];
            remove_all_filters('wp_redirect');
        }
    }
}
