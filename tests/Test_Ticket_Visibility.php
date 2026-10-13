<?php

use Escalated\Escalated;
use Escalated\Models\Department;
use Escalated\Models\Reply;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\BroadcastService;
use Escalated\Services\TicketService;

class Test_Ticket_Visibility extends WP_UnitTestCase
{
    private int $agent;

    private int $admin;

    private int $department;

    private int $other_department;

    private int $visible;

    private int $hidden;

    private string $token;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        wp_set_current_user(0);
        Setting::set('visibility', 'all');
        Setting::set('accounts_enabled', '0');
        Setting::set('accounts_scope_agents', '0');
        $this->agent = self::factory()->user->create(['role' => 'escalated_agent']);
        $this->admin = self::factory()->user->create(['role' => 'escalated_admin']);
        $this->department = Department::create(['name' => 'Visible department', 'slug' => wp_generate_uuid4()]);
        $this->other_department = Department::create(['name' => 'Hidden department', 'slug' => wp_generate_uuid4()]);
        Department::add_agent($this->department, $this->agent);
        $this->visible = $this->ticket($this->department, 'Visible access ticket');
        $this->hidden = $this->ticket($this->other_department, 'Hidden access ticket');
        Reply::create(['ticket_id' => $this->hidden, 'body' => 'Hidden reply', 'author_id' => $this->admin]);
        $this->token = wp_generate_password(64, false);
        Escalated::db()->insert(Escalated::table('api_tokens'), [
            'user_id' => $this->agent, 'name' => 'Access test',
            'token' => hash('sha256', $this->token), 'abilities' => '["*"]',
            'created_at' => current_time('mysql'),
        ]);
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
        Setting::set('visibility', 'department');
        wp_set_current_user($this->agent);
    }

    public function tear_down(): void
    {
        wp_set_current_user(0);
        BroadcastService::clear_events();
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function ticket(?int $department, string $subject): int
    {
        return Ticket::create([
            'reference' => 'ACC-'.wp_rand(10000000, 99999999), 'subject' => $subject,
            'department_id' => $department, 'status' => 'open', 'priority' => 'medium',
            'guest_token' => wp_generate_password(64, false),
        ]);
    }

    public function test_list_and_chosen_department_cannot_expand_access(): void
    {
        $result = Ticket::all(['search' => 'access ticket']);
        $this->assertSame([$this->visible], array_map('intval', array_column($result['items'], 'id')));
        $this->assertSame(0, Ticket::all(['department_id' => $this->other_department])['total']);
    }

    public function test_search_hides_foreign_ticket(): void
    {
        $this->assertSame(0, Ticket::all(['search' => 'Hidden access ticket'])['total']);
    }

    public function test_direct_lookup_hides_foreign_ticket(): void
    {
        $this->assertNull(Ticket::find($this->hidden));
        $this->assertNotNull(Ticket::find($this->visible));
    }

    public function test_reports_exclude_foreign_department(): void
    {
        ob_start();
        try {
            (new \Escalated\Admin\Admin_Reports)->render();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Visible department', $html);
        $this->assertStringNotContainsString('Hidden department', $html);
    }

    public function test_bulk_update_only_changes_visible_rows(): void
    {
        $db = Escalated::db();
        $table = Ticket::table();
        $this->assertSame(1, $db->query("UPDATE {$table} SET priority = 'high' WHERE id IN ({$this->visible}, {$this->hidden})"));
        wp_set_current_user($this->admin);
        $this->assertSame('medium', Ticket::find($this->hidden)->priority);
    }

    public function test_children_cannot_be_read_directly(): void
    {
        $this->assertSame([], Reply::for_ticket($this->hidden));
    }

    public function test_widget_hides_ticket_even_with_valid_guest_token(): void
    {
        wp_set_current_user($this->admin);
        $ticket = Ticket::find($this->hidden);
        wp_set_current_user($this->agent);
        $request = new WP_REST_Request('GET');
        $request->set_param('ref', $ticket->reference);
        $request->set_param('guest_token', $ticket->guest_token);
        $response = (new \Escalated\Api\Widget_Controller)->get_ticket($request);
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame(404, $response->get_error_data()['status']);
    }

    public function test_api_token_owner_overrides_admin_session_and_restores_context(): void
    {
        wp_set_current_user($this->admin);
        $ticket = Ticket::find($this->hidden);
        $request = new WP_REST_Request('GET', '/escalated/v1/tickets/'.$ticket->reference);
        $request->set_header('Authorization', 'Bearer '.$this->token);
        $this->assertSame(404, $this->server->dispatch($request)->get_status());
        $this->assertSame($this->admin, get_current_user_id());
        $this->assertNotNull(Ticket::find($this->hidden));
    }

    public function test_live_events_recheck_current_membership(): void
    {
        (new BroadcastService)->push_event('ticket.created', ['ticket_id' => $this->hidden]);
        $this->assertSame([], BroadcastService::get_events_since(0));
    }

    /** @dataProvider blocked_actions */
    public function test_ticket_actions_reject_foreign_ticket(string $action): void
    {
        $this->expectException(InvalidArgumentException::class);
        $service = new TicketService;
        switch ($action) {
            case 'reply': $service->reply($this->hidden, $this->agent, 'No access');
                break;
            case 'close': $service->close($this->hidden, $this->agent);
                break;
            case 'assign': (new \Escalated\Services\AssignmentService)->assign($this->hidden, $this->agent);
                break;
            case 'update': $service->update_ticket($this->hidden, ['subject' => 'Changed']);
                break;
        }
    }

    public static function blocked_actions(): array
    {
        return [['reply'], ['close'], ['assign'], ['update']];
    }

    public function test_admin_and_default_off_keep_existing_access(): void
    {
        wp_set_current_user($this->admin);
        $this->assertNotNull(Ticket::find($this->hidden));
        Setting::set('visibility', 'all');
        wp_set_current_user($this->agent);
        $this->assertNotNull(Ticket::find($this->hidden));
    }

    public function test_unowned_departments_and_removed_memberships_fail_closed(): void
    {
        wp_set_current_user($this->admin);
        $unowned = $this->ticket(null, 'No department');
        wp_set_current_user($this->agent);
        $this->assertNull(Ticket::find($unowned));
        Department::remove_agent($this->department, $this->agent);
        $this->assertNull(Ticket::find($this->visible));
        wp_set_current_user(0);
        $this->assertNotNull(Ticket::find($unowned));
    }

    public function test_account_scope_intersects_department_and_preserves_opaque_ids(): void
    {
        wp_set_current_user($this->admin);
        Setting::set('accounts_enabled', '1');
        Setting::set('accounts_scope_agents', '1');
        Ticket::update($this->visible, ['account_type' => 'Company', 'account_id' => '001']);
        Ticket::update($this->hidden, ['account_type' => 'Company', 'account_id' => '001']);
        $resolver = static fn () => ['type' => 'Company', 'id' => '001'];
        add_filter('escalated_agent_account', $resolver);
        try {
            wp_set_current_user($this->agent);
            $this->assertNotNull(Ticket::find($this->visible));
            $this->assertNull(Ticket::find($this->hidden));
            foreach ([['Company', '1'], ['company', '001'], ['Company', '001 ']] as [$type, $id]) {
                \Escalated\Services\TicketAccess::without_restriction(fn () => Ticket::update($this->visible, ['account_type' => $type, 'account_id' => $id]));
                $this->assertNull(Ticket::find($this->visible));
            }
        } finally {
            remove_filter('escalated_agent_account', $resolver);
        }
        $this->assertSame([], Ticket::all()['items']);
    }

    public function test_trusted_bypass_and_actor_context_restore_after_exceptions(): void
    {
        try {
            \Escalated\Services\TicketAccess::without_restriction(function () {
                $this->assertNotNull(Ticket::find($this->hidden));
                $this->assertFalse(\Escalated\Services\TicketAccess::can_receive($this->hidden, $this->agent));
                throw new RuntimeException('test');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('test', $e->getMessage());
        }
        $this->assertNull(Ticket::find($this->hidden));
        try {
            \Escalated\Services\TicketAccess::run_as($this->admin, static function () {
                throw new RuntimeException('actor');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('actor', $e->getMessage());
        }
        $this->assertNull(Ticket::find($this->hidden));
    }

    public function test_allowed_agent_actions_and_department_transfer(): void
    {
        $service = new TicketService;
        $service->update_ticket($this->visible, ['subject' => 'Allowed update']);
        $reply = $service->reply($this->visible, $this->agent, 'Allowed reply');
        $this->assertSame($this->visible, (int) $reply->ticket_id);
        $ticket = (new \Escalated\Services\AssignmentService)->assign($this->visible, $this->agent);
        $this->assertSame($this->agent, (int) $ticket->assigned_to);
        $service->change_department($this->visible, $this->other_department);
        $this->assertNull(Ticket::find($this->visible));
        wp_set_current_user($this->admin);
        $this->assertNull(Ticket::find($this->visible)->assigned_to);
    }

    public function test_assignment_candidates_and_admin_assignment_obey_recipient_scope(): void
    {
        wp_set_current_user($this->admin);
        $ticket = Ticket::find($this->hidden);
        $candidates = (new \Escalated\Services\SkillRoutingService)->find_matching_agents($ticket);
        $this->assertNotContains($this->agent, array_column($candidates, 'id'));
        $this->expectException(InvalidArgumentException::class);
        (new \Escalated\Services\AssignmentService)->assign($this->hidden, $this->agent);
    }

    public function test_api_assignment_returns_denial_and_allows_eligible_agent(): void
    {
        $ticket = Ticket::find($this->visible);
        $other = self::factory()->user->create(['role' => 'escalated_agent']);
        foreach ([[$other, 422], [$this->agent, 200]] as [$agent, $expected]) {
            $request = new WP_REST_Request('POST', '/escalated/v1/tickets/'.$ticket->reference.'/assign');
            $request->set_header('Authorization', 'Bearer '.$this->token);
            $request->set_param('agent_id', $agent);
            $this->assertSame($expected, $this->server->dispatch($request)->get_status());
        }
    }

    public function test_anonymous_api_uses_token_owner_and_nonstaff_token_is_rejected(): void
    {
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET', '/escalated/v1/tickets');
        $request->set_header('Authorization', 'Bearer '.$this->token);
        $response = $this->server->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame([$this->visible], array_map('intval', array_column($response->get_data()['items'], 'id')));
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        Escalated::db()->update(Escalated::table('api_tokens'), ['user_id' => $subscriber], ['token' => hash('sha256', $this->token)]);
        $this->assertSame(401, $this->server->dispatch($request)->get_status());
    }

    public function test_notifications_recheck_fresh_ticket_after_recipient_filter(): void
    {
        wp_set_current_user($this->admin);
        $stale = Ticket::find($this->visible);
        $agent_email = get_userdata($this->agent)->user_email;
        $recipients = function () use ($agent_email) {
            Ticket::update($this->visible, ['department_id' => $this->other_department]);

            return [$agent_email, get_userdata($this->admin)->user_email];
        };
        $sent = [];
        $mail = static function ($result, $args) use (&$sent) {
            $sent[] = $args['to'];

            return true;
        };
        add_filter('escalated_notify_ticket_created_recipients', $recipients);
        add_filter('pre_wp_mail', $mail, 10, 2);
        try {
            (new \Escalated\Services\NotificationService)->notify_ticket_created($stale);
            $this->assertSame([get_userdata($this->admin)->user_email], $sent);
        } finally {
            remove_filter('escalated_notify_ticket_created_recipients', $recipients);
            remove_filter('pre_wp_mail', $mail);
        }
    }

    public function test_raw_child_insert_cannot_attach_to_hidden_ticket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Escalated::db()->insert(Escalated::table('replies'), ['ticket_id' => $this->hidden, 'body' => 'Denied']);
    }

    public function test_child_reparent_cannot_move_to_hidden_ticket(): void
    {
        $id = Reply::create(['ticket_id' => $this->visible, 'body' => 'Visible']);
        $this->expectException(InvalidArgumentException::class);
        Reply::update($id, ['ticket_id' => $this->hidden]);
    }

    public function test_bulk_delete_does_not_delete_hidden_rows(): void
    {
        $table = Ticket::table();
        $this->assertSame(1, Escalated::db()->query("DELETE FROM {$table} WHERE id = {$this->hidden} OR id = {$this->visible}"));
        wp_set_current_user($this->admin);
        $this->assertNotNull(Ticket::find($this->hidden));
    }

    public function test_nested_selects_unions_and_quoted_literals_keep_scope(): void
    {
        $table = Ticket::table();
        $db = Escalated::db();
        $sql = "SELECT id FROM `{$table}` WHERE subject <> 'FROM {$table}' UNION SELECT t.id FROM `{$table}` t WHERE id IN (SELECT id FROM `{$table}`)";
        $this->assertSame([$this->visible], array_map('intval', $db->get_col($sql)));
        $this->assertSame(1, (int) $db->get_var("SELECT COUNT(*) FROM /* ignored table comment */ `{$table}`"));
    }

    /** @dataProvider unsupported_sql */
    public function test_unsupported_protected_sql_fails_closed(string $template): void
    {
        $this->expectException(InvalidArgumentException::class);
        Escalated::db()->query(sprintf($template, Ticket::table()));
    }

    public static function unsupported_sql(): array
    {
        return [
            ['SELECT * FROM (SELECT 1 AS x) dummy, %s'],
            ['WITH visible AS (SELECT * FROM %s) SELECT * FROM visible'],
            ['REPLACE INTO %s (id, subject) VALUES (1, \'Denied\')'],
            ['INSERT INTO %s (id) SELECT 1'],
            ['SELECT 1 /*! UNION SELECT id FROM %s */'],
            ['SELECT id FROM %s; SELECT 1'],
        ];
    }

    /** @dataProvider child_tables */
    public function test_all_direct_child_queries_are_scoped(string $name, array $fields): void
    {
        wp_set_current_user($this->admin);
        $table = Escalated::table($name);
        $fields['ticket_id'] = $this->hidden;
        $this->assertNotFalse(Escalated::db()->insert($table, $fields));
        wp_set_current_user($this->agent);
        $this->assertSame([], Escalated::db()->get_results("SELECT * FROM `{$table}` WHERE ticket_id = {$this->hidden}"));
    }

    public static function child_tables(): array
    {
        $now = '2026-10-01 12:00:00';

        return [
            ['ticket_tag', ['tag_id' => 1]],
            ['ticket_activities', ['type' => 'test']],
            ['ticket_followers', ['user_id' => 1]],
            ['satisfaction_ratings', ['rating' => 5]],
            ['workflow_logs', ['workflow_id' => 1, 'trigger_event' => 'ticket.created', 'started_at' => $now, 'created_at' => $now]],
            ['chat_sessions', ['created_at' => $now, 'updated_at' => $now, 'last_activity_at' => $now]],
            ['deferred_workflow_jobs', ['remaining_actions' => '[]', 'run_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['ticket_subjects', ['subject_type' => 'Order', 'subject_id' => '001', 'created_at' => $now, 'updated_at' => $now]],
            ['side_conversations', ['subject' => 'Private', 'channel' => 'email', 'status' => 'open']],
            ['inbound_emails', ['body_text' => 'Private email']],
        ];
    }

    public function test_links_attachments_side_replies_and_ticket_audit_require_visible_parents(): void
    {
        wp_set_current_user($this->admin);
        $db = Escalated::db();
        foreach ([[$this->visible, $this->hidden], [$this->hidden, $this->visible]] as [$first, $second]) {
            $db->insert(Escalated::table('ticket_links'), ['ticket_id' => $first, 'linked_ticket_id' => $second]);
        }
        $reply = Reply::create(['ticket_id' => $this->hidden, 'body' => 'Secret']);
        $db->insert(Escalated::table('attachments'), ['attachable_type' => 'reply', 'attachable_id' => $reply]);
        $attachment = $db->insert_id;
        $db->insert(Escalated::table('side_conversations'), ['ticket_id' => $this->hidden, 'subject' => 'Secret', 'channel' => 'email', 'status' => 'open']);
        $side = $db->insert_id;
        $db->insert(Escalated::table('side_conversation_replies'), ['side_conversation_id' => $side, 'body' => 'Secret']);
        $audit = \Escalated\Models\AuditLog::record('test', 'Ticket', $this->hidden);
        $db->insert(Escalated::table('inbound_emails'), ['ticket_id' => $this->visible, 'reply_id' => $reply, 'body_text' => 'Secret']);
        $inbound = $db->insert_id;
        wp_set_current_user($this->agent);
        $this->assertSame([], (new \Escalated\Services\TicketSplitService)->get_linked_tickets($this->visible));
        $this->assertNull(\Escalated\Models\Attachment::find($attachment));
        $this->assertSame([], \Escalated\Models\SideConversationReply::for_conversation($side));
        $this->assertNull($db->get_row('SELECT * FROM '.Escalated::table('audit_logs').' WHERE id = '.(int) $audit));
        $this->assertNull($db->get_row('SELECT * FROM '.Escalated::table('inbound_emails').' WHERE id = '.(int) $inbound));
    }

    public function test_chat_routing_does_not_select_ineligible_agent(): void
    {
        wp_set_current_user($this->admin);
        (new \Escalated\Services\ChatRoutingService)->create_rule([
            'name' => 'Access routing', 'department_id' => $this->other_department,
            'agent_id' => $this->agent,
        ]);
        $session = (new \Escalated\Services\ChatSessionService)->start('Visitor', 'visitor@example.org', 'Hello', $this->other_department);
        $this->assertNull($session->agent_id);
        $this->assertSame('waiting', $session->status);
    }

    public function test_chat_accept_checks_recipient_before_changing_session(): void
    {
        wp_set_current_user($this->admin);
        $id = \Escalated\Models\ChatSession::create(['ticket_id' => $this->hidden]);
        try {
            (new \Escalated\Services\ChatSessionService)->accept($id, $this->agent);
            $this->fail('Ineligible agent accepted chat.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('waiting', \Escalated\Models\ChatSession::find($id)->status);
            $this->assertNull(\Escalated\Models\ChatSession::find($id)->agent_id);
        }
    }
}
