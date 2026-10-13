<?php

use Escalated\Escalated;
use Escalated\Models\Department;
use Escalated\Models\Setting;
use Escalated\Models\SlaPolicy;
use Escalated\Models\Ticket;
use Escalated\Services\AutomationRunner;
use Escalated\Services\EscalationService;
use Escalated\Services\SlaService;
use Escalated\Services\TicketService;
use Escalated\Services\WorkflowEngine;

class Test_Ticket_Access_Routing extends WP_UnitTestCase
{
    private int $department;

    private int $ticket;

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(0);
        Setting::set('visibility', 'all');
        Setting::set('accounts_enabled', '1');
        Setting::set('accounts_scope_agents', '0');
        Setting::set('targeted_slas', '0');
        $this->department = Department::create(['name' => 'Routing', 'slug' => wp_generate_uuid4()]);
        $this->ticket = $this->ticket('Company', '001');
        $this->ticket('Company', '1');
        $this->ticket('company', '001');
        $this->ticket('Company', '001 ');
    }

    private function ticket(string $type, string $id): int
    {
        return Ticket::create([
            'reference' => 'RTE-'.wp_rand(10000000, 99999999), 'subject' => 'Routing',
            'status' => 'open', 'priority' => 'medium', 'department_id' => $this->department,
            'ticket_type' => 'incident', 'account_type' => $type, 'account_id' => $id,
        ]);
    }

    /** @dataProvider engines */
    public function test_account_conditions_match_exact_pair(string $engine): void
    {
        $conditions = [
            ['field' => 'department_id', 'value' => $this->department],
            ['field' => 'ticket_type', 'value' => 'incident'],
            ['field' => 'account', 'value' => ['type' => 'Company', 'id' => '001']],
        ];
        if ($engine === 'workflow') {
            $workflow = new WorkflowEngine;
            $this->assertTrue($workflow->evaluateConditions($conditions, (array) Ticket::find($this->ticket)));
            $conditions[2]['value']['id'] = '1';
            $this->assertFalse($workflow->evaluateConditions($conditions, (array) Ticket::find($this->ticket)));
        } else {
            $service = $engine === 'escalation' ? new EscalationService : new class extends AutomationRunner
            {
                public function match(object $rule): array
                {
                    return $this->find_matching_tickets($rule);
                }
            };
            $rule = (object) ['conditions' => wp_json_encode($conditions)];
            $rows = $engine === 'escalation' ? $service->find_matching_tickets($rule) : $service->match($rule);
            $this->assertSame([$this->ticket], array_map('intval', array_column($rows, 'id')));
        }
    }

    public static function engines(): array
    {
        return [['workflow'], ['escalation'], ['automation']];
    }

    public function test_malformed_account_condition_never_matches(): void
    {
        $condition = ['field' => 'account', 'operator' => 'not_equals', 'value' => ['type' => 'Company']];
        $this->assertFalse((new WorkflowEngine)->evaluateConditions([$condition], (array) Ticket::find($this->ticket)));
        $this->assertSame([], (new EscalationService)->find_matching_tickets((object) ['conditions' => wp_json_encode([$condition])]));
    }

    public function test_targeted_sla_prefers_department_and_type_and_move_preserves_clock(): void
    {
        $default = $this->policy([], 12);
        $type = $this->policy(['ticket_type' => 'incident', 'is_default' => 0], 8);
        $department = $this->policy(['department_id' => $this->department, 'is_default' => 0], 4);
        $both = $this->policy(['department_id' => $this->department, 'ticket_type' => 'incident', 'is_default' => 0], 1);
        Setting::set('targeted_slas', '1');
        $service = new SlaService;
        $this->assertTrue($service->attach_default_policy($this->ticket));
        $ticket = Ticket::find($this->ticket);
        $this->assertSame($both, (int) $ticket->sla_policy_id);
        $due = $ticket->resolution_due_at;
        $other = Department::create(['name' => 'Other', 'slug' => wp_generate_uuid4()]);
        (new TicketService)->change_department($this->ticket, $other);
        $this->assertSame($due, Ticket::find($this->ticket)->resolution_due_at);
        $service->attach_default_policy($this->ticket);
        $ticket = Ticket::find($this->ticket);
        $this->assertSame($type, (int) $ticket->sla_policy_id);
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime($ticket->created_at) + 8 * HOUR_IN_SECONDS), $ticket->resolution_due_at);
        SlaPolicy::delete($both);
        SlaPolicy::delete($type);
        Ticket::update($this->ticket, ['department_id' => $this->department]);
        $service->attach_default_policy($this->ticket);
        $this->assertSame($department, (int) Ticket::find($this->ticket)->sla_policy_id);
        SlaPolicy::delete($department);
        $service->attach_default_policy($this->ticket);
        $this->assertSame($default, (int) Ticket::find($this->ticket)->sla_policy_id);
    }

    public function test_targeted_slas_off_preserves_default_selection(): void
    {
        $default = $this->policy([], 12);
        $this->policy(['department_id' => $this->department, 'is_default' => 0], 1);
        (new SlaService)->attach_default_policy($this->ticket);
        $this->assertSame($default, (int) Ticket::find($this->ticket)->sla_policy_id);
    }

    private function policy(array $targets, int $hours): int
    {
        // Only this test's default policy should participate.
        if ($targets === []) {
            Escalated::db()->query('UPDATE '.SlaPolicy::table().' SET is_default = 0');
        }

        return SlaPolicy::create(array_merge([
            'name' => 'Routing SLA', 'is_active' => 1, 'is_default' => 1,
            'first_response_hours' => ['medium' => $hours], 'resolution_hours' => ['medium' => $hours],
        ], $targets));
    }

    public function test_requester_and_subject_resolvers_are_opt_in_and_never_overwrite_accounts(): void
    {
        $resolver = static function ($value, $data, $subject) {
            return ['type' => 'Company', 'id' => $subject ? 'subject-001' : 'requester-001'];
        };
        add_filter('escalated_ticket_account', $resolver, 10, 3);
        try {
            Setting::set('accounts_enabled', '0');
            $service = new TicketService;
            $requester = self::factory()->user->create(['role' => 'subscriber']);
            $off = $service->create($requester, ['subject' => 'Off', 'description' => 'Test']);
            $this->assertNull($off->account_id);
            Setting::set('accounts_enabled', '1');
            $on = $service->create($requester, ['subject' => 'On', 'description' => 'Test']);
            $this->assertSame('requester-001', $on->account_id);
            \Escalated\Services\TicketSubjectService::attach((int) $off->id, 'Order', '001', null, null, false);
            $this->assertSame('subject-001', Ticket::find($off->id)->account_id);
            \Escalated\Services\TicketSubjectService::attach((int) $on->id, 'Order', '002', null, null, false);
            $this->assertSame('requester-001', Ticket::find($on->id)->account_id);
            $reply = \Escalated\Models\Reply::create(['ticket_id' => $on->id, 'body' => 'Split']);
            $split = (new \Escalated\Services\TicketSplitService)->split_ticket((int) $reply);
            $this->assertSame('requester-001', $split->account_id);
            $this->assertSame('Company', $split->account_type);
        } finally {
            remove_filter('escalated_ticket_account', $resolver);
        }
    }

    public function test_account_filter_requires_complete_pair_and_preserves_percent_and_quotes(): void
    {
        $this->assertSame([$this->ticket], array_map('intval', array_column(Ticket::all(['account_type' => 'Company', 'account_id' => '001'])['items'], 'id')));
        $this->assertSame(0, Ticket::all(['account_id' => '001'])['total']);
        $id = $this->ticket('Company', "percent%s'quote");
        $rows = Ticket::all(['department_id' => $this->department, 'account_type' => 'Company', 'account_id' => "percent%s'quote"]);
        $this->assertSame([$id], array_map('intval', array_column($rows['items'], 'id')));
    }

    public function test_targeted_policy_is_attached_before_created_event(): void
    {
        $policy = $this->policy(['department_id' => $this->department, 'ticket_type' => 'incident', 'is_default' => 0], 1);
        Setting::set('targeted_slas', '1');
        $seen = null;
        $listener = static function ($ticket) use (&$seen) {
            $seen = (int) $ticket->sla_policy_id;
        };
        add_action('escalated_ticket_created', $listener);
        try {
            $requester = self::factory()->user->create(['role' => 'subscriber']);
            (new TicketService)->create($requester, ['subject' => 'SLA', 'description' => 'Test', 'department_id' => $this->department, 'ticket_type' => 'incident']);
            $this->assertSame($policy, $seen);
        } finally {
            remove_action('escalated_ticket_created', $listener);
        }
    }

    public function test_settings_reject_invalid_visibility_and_preserve_unsent_access_flags(): void
    {
        $settings = new \Escalated\Admin\Admin_Settings;
        $settings->persist(['visibility' => 'department', 'accounts_enabled' => 1, 'accounts_scope_agents' => 1, 'targeted_slas' => 1]);
        $settings->persist(['visibility' => 'invalid', 'default_priority' => 'high']);
        $this->assertSame('department', Setting::get('visibility'));
        $this->assertTrue(Setting::get_bool('accounts_enabled'));
        $this->assertTrue(Setting::get_bool('accounts_scope_agents'));
        $this->assertTrue(Setting::get_bool('targeted_slas'));
    }
}
