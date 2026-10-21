<?php

/**
 * Workflow admin: the REST routes and the wp-admin screen, held to the
 * workflow admin contract (escalated-developer-context
 * domain-model/workflow-admin-contract.md). Request bodies are the
 * contract's, sent through the real REST server, and saved workflows are run
 * through the real services.
 */

use Escalated\Admin\Admin_Workflows;
use Escalated\Models\Department;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Models\Workflow;
use Escalated\Services\TicketService;
use Escalated\Services\WorkflowEngine;

class Test_Workflow_Admin extends WP_UnitTestCase
{
    private const EXAMPLE = [
        'name' => 'Route refunds to billing',
        'description' => null,
        'trigger_event' => 'ticket.created',
        'conditions' => [
            'all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']],
        ],
        'actions' => [
            ['type' => 'change_priority', 'value' => 'high'],
            ['type' => 'set_department', 'value' => '4'],
        ],
        'is_active' => true,
    ];

    private int $admin_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->admin_id = $this->factory->user->create(['role' => 'escalated_admin']);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user(0);

        parent::tear_down();
    }

    // =========================================================================
    // The contract's three checks
    // =========================================================================

    public function test_creating_with_the_contract_example_stores_it_as_sent(): void
    {
        $response = $this->send('POST', '/admin/workflows', self::EXAMPLE);

        $this->assertSame(201, $response->get_status());
        $workflow = Workflow::format(Workflow::find($response->get_data()['workflow']['id']));
        $this->assertSame('Route refunds to billing', $workflow['name']);
        $this->assertSame('ticket.created', $workflow['trigger_event']);
        $this->assertSame(self::EXAMPLE['conditions'], $workflow['conditions']);
        $this->assertSame(self::EXAMPLE['actions'], $workflow['actions']);
        $this->assertTrue($workflow['is_active']);
    }

    public function test_a_workflow_saved_through_the_api_runs_on_its_trigger(): void
    {
        $billing = $this->make_department();
        $body = self::EXAMPLE;
        $body['actions'][1]['value'] = (string) $billing;
        $this->assertSame(201, $this->send('POST', '/admin/workflows', $body)->get_status());

        $tickets = new TicketService;
        $requester = $this->factory->user->create(['role' => 'subscriber']);
        $refund = $tickets->create($requester, ['subject' => 'I want a refund', 'description' => 'Broken.', 'priority' => 'low']);
        $other = $tickets->create($requester, ['subject' => 'Password reset', 'description' => 'Locked out.', 'priority' => 'low']);

        $refund = Ticket::find((int) $refund->id);
        $this->assertSame('high', $refund->priority);
        $this->assertSame($billing, (int) $refund->department_id);

        $other = Ticket::find((int) $other->id);
        $this->assertSame('low', $other->priority);
    }

    public function test_form_props_list_what_the_plugin_can_do(): void
    {
        $data = $this->send('GET', '/admin/workflows/new')->get_data();

        $this->assertNull($data['workflow']);
        $this->assertSame(WorkflowEngine::TRIGGER_EVENTS, array_column($data['trigger_events'], 'value'));
        $this->assertNotEmpty($data['trigger_events'][0]['label']);
        foreach (['change_status', 'change_priority', 'add_tag', 'remove_tag', 'set_department', 'assign_agent', 'add_note', 'insert_canned_reply'] as $core) {
            $this->assertContains($core, array_column($data['action_types'], 'value'));
        }
        foreach (array_column($data['action_types'], 'value') as $type) {
            $this->assertContains($type, WorkflowEngine::ACTION_TYPES, "{$type} is offered but not executed.");
        }
        $this->assertSame(WorkflowEngine::OPERATORS, $data['operators']);
    }

    // =========================================================================
    // Access
    // =========================================================================

    public function test_routes_need_a_signed_in_admin(): void
    {
        wp_set_current_user(0);
        $this->assertSame(401, $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/workflows'))->get_status());

        wp_set_current_user($this->factory->user->create(['role' => 'escalated_agent']));
        $this->assertSame(403, $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/workflows'))->get_status());

        $request = new WP_REST_Request('POST', '/escalated/v1/admin/workflows');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(self::EXAMPLE));
        $this->assertSame(403, $this->server->dispatch($request)->get_status());
        $this->assertSame([], Workflow::all());
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * @dataProvider invalid_bodies
     */
    public function test_invalid_bodies_are_refused(array $changes, string $field): void
    {
        $body = array_merge(self::EXAMPLE, $changes);
        foreach ($changes as $key => $value) {
            if ($value === '__unset') {
                unset($body[$key]);
            }
        }

        $response = $this->send('POST', '/admin/workflows', $body);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey($field, $response->get_data()['errors']);
        $this->assertSame([], Workflow::all());
    }

    public static function invalid_bodies(): array
    {
        return [
            'no name' => [['name' => '__unset'], 'name'],
            'blank name' => [['name' => '  '], 'name'],
            'no trigger' => [['trigger_event' => '__unset'], 'trigger_event'],
            'unknown trigger' => [['trigger_event' => 'ticket.exploded'], 'trigger_event'],
            'no actions' => [['actions' => '__unset'], 'actions'],
            'empty actions' => [['actions' => []], 'actions'],
            'unknown action' => [['actions' => [['type' => 'launch_rocket', 'value' => '1']]], 'actions'],
            'action value not scalar' => [['actions' => [['type' => 'add_tag', 'value' => ['a']]]], 'actions'],
            'both all and any' => [['conditions' => ['all' => [], 'any' => []]], 'conditions'],
            'old match/rules shape' => [['conditions' => ['match' => 'all', 'rules' => []]], 'conditions'],
            'unknown operator' => [['conditions' => ['all' => [['field' => 'subject', 'operator' => 'sounds_like', 'value' => 'x']]]], 'conditions'],
            'condition without field' => [['conditions' => ['any' => [['operator' => 'equals', 'value' => 'x']]]], 'conditions'],
        ];
    }

    public function test_omitted_conditions_match_every_ticket(): void
    {
        $body = self::EXAMPLE;
        unset($body['conditions']);

        $id = $this->send('POST', '/admin/workflows', $body)->get_data()['workflow']['id'];

        $this->assertSame(['all' => []], Workflow::format(Workflow::find($id))['conditions']);
    }

    public function test_a_legacy_trigger_name_is_stored_under_its_canonical_name(): void
    {
        $body = array_merge(self::EXAMPLE, ['trigger_event' => 'ticket.tagged']);

        $id = $this->send('POST', '/admin/workflows', $body)->get_data()['workflow']['id'];

        $this->assertSame('ticket.tag_added', Workflow::find($id)->trigger_event);
    }

    // =========================================================================
    // Index, update, toggle, reorder, delete
    // =========================================================================

    public function test_index_shows_stored_rows_in_the_contract_shape(): void
    {
        global $wpdb;
        // An older row: legacy trigger name and a flat list of conditions.
        $wpdb->insert(Workflow::table(), [
            'name' => 'Old row',
            'trigger_event' => 'ticket.replied',
            'conditions' => wp_json_encode([['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]),
            'actions' => wp_json_encode([['type' => 'add_tag', 'value' => 'vip']]),
            'is_active' => 1,
            'position' => 0,
            'stop_on_match' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        $workflows = $this->send('GET', '/admin/workflows')->get_data()['workflows'];

        $this->assertCount(1, $workflows);
        $this->assertSame('reply.created', $workflows[0]['trigger_event']);
        $this->assertSame(['all' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]], $workflows[0]['conditions']);
        $this->assertTrue($workflows[0]['is_active']);
        $this->assertIsInt($workflows[0]['position']);
    }

    public function test_update_replaces_the_workflow(): void
    {
        $id = $this->send('POST', '/admin/workflows', self::EXAMPLE)->get_data()['workflow']['id'];
        $body = array_merge(self::EXAMPLE, [
            'name' => 'Refunds are urgent',
            'trigger_event' => 'reply.created',
            'conditions' => ['any' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund'], ['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']]],
            'actions' => [['type' => 'change_priority', 'value' => 'urgent']],
            'is_active' => false,
        ]);

        $response = $this->send('PUT', '/admin/workflows/'.$id, $body);

        $this->assertSame(200, $response->get_status());
        $stored = Workflow::format(Workflow::find($id));
        $this->assertSame('Refunds are urgent', $stored['name']);
        $this->assertSame('reply.created', $stored['trigger_event']);
        $this->assertSame($body['conditions'], $stored['conditions']);
        $this->assertSame($body['actions'], $stored['actions']);
        $this->assertFalse($stored['is_active']);

        $edit = $this->send('GET', '/admin/workflows/'.$id.'/edit')->get_data();
        $this->assertSame('Refunds are urgent', $edit['workflow']['name']);
        $this->assertArrayHasKey('trigger_events', $edit);
    }

    public function test_toggle_reorder_and_delete(): void
    {
        $a = $this->send('POST', '/admin/workflows', array_merge(self::EXAMPLE, ['name' => 'A']))->get_data()['workflow']['id'];
        $b = $this->send('POST', '/admin/workflows', array_merge(self::EXAMPLE, ['name' => 'B']))->get_data()['workflow']['id'];
        $c = $this->send('POST', '/admin/workflows', array_merge(self::EXAMPLE, ['name' => 'C']))->get_data()['workflow']['id'];

        $this->send('POST', '/admin/workflows/'.$a.'/toggle');
        $this->assertSame(0, (int) Workflow::find($a)->is_active);
        $this->send('POST', '/admin/workflows/'.$a.'/toggle');
        $this->assertSame(1, (int) Workflow::find($a)->is_active);

        $response = $this->send('POST', '/admin/workflows/reorder', ['workflow_ids' => [$c, $a, $b]]);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(['C', 'A', 'B'], array_column($this->send('GET', '/admin/workflows')->get_data()['workflows'], 'name'));

        $this->assertSame(200, $this->send('DELETE', '/admin/workflows/'.$b)->get_status());
        $this->assertNull(Workflow::find($b));
        $this->assertSame(404, $this->send('DELETE', '/admin/workflows/'.$b)->get_status());
    }

    // =========================================================================
    // Contract fields and actions the engine now honours
    // =========================================================================

    public function test_tags_and_note_templates_work_in_a_saved_workflow(): void
    {
        $tag_id = $this->make_tag('vip');
        $this->send('POST', '/admin/workflows', [
            'name' => 'VIP note',
            'trigger_event' => 'ticket.tag_added',
            'conditions' => ['all' => [['field' => 'tags', 'operator' => 'contains', 'value' => 'vip']]],
            'actions' => [['type' => 'add_note', 'value' => 'VIP ticket: {{subject}}']],
        ]);

        $tickets = new TicketService;
        $ticket = $tickets->create($this->factory->user->create(), ['subject' => 'Gold member', 'description' => 'Hi', 'priority' => 'low']);
        $tickets->add_tags((int) $ticket->id, [$tag_id]);

        global $wpdb;
        $replies = \Escalated\Escalated::table('replies');
        $notes = $wpdb->get_col($wpdb->prepare("SELECT body FROM {$replies} WHERE ticket_id = %d AND is_internal_note = 1", $ticket->id));
        $this->assertContains('VIP ticket: Gold member', $notes);
    }

    public function test_a_ticket_updated_workflow_that_updates_the_ticket_runs_once(): void
    {
        $id = $this->send('POST', '/admin/workflows', [
            'name' => 'Bump',
            'trigger_event' => 'ticket.updated',
            'actions' => [['type' => 'change_priority', 'value' => 'high']],
        ])->get_data()['workflow']['id'];

        $tickets = new TicketService;
        $ticket = $tickets->create($this->factory->user->create(), ['subject' => 'Loop', 'description' => 'x', 'priority' => 'low']);
        $tickets->update_ticket((int) $ticket->id, ['subject' => 'Loop again']);

        global $wpdb;
        $logs = \Escalated\Escalated::table('workflow_logs');
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE workflow_id = %d", $id)));
        $this->assertSame('high', Ticket::find((int) $ticket->id)->priority);
    }

    public function test_hours_since_created_is_a_condition_field(): void
    {
        $this->send('POST', '/admin/workflows', [
            'name' => 'Old tickets',
            'trigger_event' => 'ticket.updated',
            'conditions' => ['all' => [['field' => 'hours_since_created', 'operator' => 'greater_or_equal', 'value' => '48']]],
            'actions' => [['type' => 'change_priority', 'value' => 'urgent']],
        ]);

        $tickets = new TicketService;
        $fresh = $tickets->create($this->factory->user->create(), ['subject' => 'Fresh', 'description' => 'x', 'priority' => 'low']);
        $old = $tickets->create($this->factory->user->create(), ['subject' => 'Old', 'description' => 'x', 'priority' => 'low']);
        Ticket::update((int) $old->id, ['created_at' => gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - 3 * DAY_IN_SECONDS)]);

        $tickets->update_ticket((int) $fresh->id, ['subject' => 'Fresh still']);
        $tickets->update_ticket((int) $old->id, ['subject' => 'Old still']);

        $this->assertSame('low', Ticket::find((int) $fresh->id)->priority);
        $this->assertSame('urgent', Ticket::find((int) $old->id)->priority);
    }

    // =========================================================================
    // wp-admin screen
    // =========================================================================

    public function test_the_screen_saves_a_workflow_in_the_contract_shape(): void
    {
        wp_set_current_user($this->admin_id);

        $redirect = (new Admin_Workflows)->process([
            'escalated_workflow_action' => 'create',
            '_escalated_nonce' => wp_create_nonce('escalated_workflow_create'),
            'name' => 'Screen made',
            'trigger_event' => 'ticket.created',
            'match' => 'any',
            'conditions' => '[{"field": "subject", "operator": "contains", "value": "refund"}]',
            'actions_json' => '[{"type": "change_priority", "value": "high"}]',
            'is_active' => '1',
        ]);

        $this->assertStringContainsString('message=created', $redirect);
        $stored = Workflow::format(Workflow::all()[0]);
        $this->assertSame('Screen made', $stored['name']);
        $this->assertSame(['any' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']]], $stored['conditions']);
        $this->assertSame([['type' => 'change_priority', 'value' => 'high']], $stored['actions']);
        $this->assertTrue($stored['is_active']);
    }

    public function test_the_screen_refuses_bad_json_and_keeps_what_was_typed(): void
    {
        wp_set_current_user($this->admin_id);

        $redirect = (new Admin_Workflows)->process([
            'escalated_workflow_action' => 'create',
            '_escalated_nonce' => wp_create_nonce('escalated_workflow_create'),
            'name' => 'Broken',
            'trigger_event' => 'ticket.created',
            'conditions' => '',
            'actions_json' => '[{"type": "change_priority", ',
        ]);

        $this->assertStringContainsString('message=invalid', $redirect);
        $this->assertSame([], Workflow::all());

        $_GET = ['message' => 'invalid'];
        ob_start();
        (new Admin_Workflows)->render();
        $html = ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString('Actions must be a JSON list', $html);
        $this->assertStringContainsString('value="Broken"', $html);
    }

    public function test_the_screen_toggles_and_deletes(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->send('POST', '/admin/workflows', self::EXAMPLE)->get_data()['workflow']['id'];

        (new Admin_Workflows)->process([
            'escalated_workflow_action' => 'toggle',
            'id' => (string) $id,
            '_escalated_nonce' => wp_create_nonce('escalated_workflow_toggle_'.$id),
        ]);
        $this->assertSame(0, (int) Workflow::find($id)->is_active);

        ob_start();
        (new Admin_Workflows)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString('Route refunds to billing', $html);
        $this->assertStringContainsString('value="ticket.tag_added"', $html);

        (new Admin_Workflows)->process([
            'escalated_workflow_action' => 'delete',
            'id' => (string) $id,
            '_escalated_nonce' => wp_create_nonce('escalated_workflow_delete_'.$id),
        ]);
        $this->assertNull(Workflow::find($id));
    }

    public function test_the_screen_needs_a_valid_nonce(): void
    {
        wp_set_current_user($this->admin_id);
        $this->expectException(WPDieException::class);

        (new Admin_Workflows)->process([
            'escalated_workflow_action' => 'create',
            '_escalated_nonce' => 'forged',
            'name' => 'x',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function send(string $method, string $path, ?array $body = null): WP_REST_Response
    {
        wp_set_current_user($this->admin_id);
        $request = new WP_REST_Request($method, '/escalated/v1'.$path);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function make_department(): int
    {
        $slug = 'billing-'.strtolower(wp_generate_password(6, false, false));

        return (int) Department::create(['name' => $slug, 'slug' => $slug, 'description' => '', 'is_active' => 1]);
    }

    private function make_tag(string $name): int
    {
        global $wpdb;
        $wpdb->insert(Tag::table(), [
            'name' => $name,
            'slug' => $name.'-'.strtolower(wp_generate_password(4, false, false)),
            'color' => '#336699',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }
}
