<?php

/**
 * Tests for the system-wide audit log: the escalated_audit_logs table, the
 * AuditLog model + record() helper, the audit recording wired into key
 * mutation sites (settings + webhooks, role grants, API tokens, 2FA, and
 * knowledge base CRUD), and the admin list/filter REST surface.
 */

use Escalated\Activator;
use Escalated\Admin\Admin_Settings;
use Escalated\Admin\Admin_Users;
use Escalated\Models\ApiToken;
use Escalated\Models\AuditLog;
use Escalated\Services\TwoFactorService;

class Test_Audit_Log extends WP_UnitTestCase
{
    private int $admin_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        Activator::activate();

        $this->admin_id = $this->factory->user->create(['role' => 'escalated_admin']);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function json_request(string $method, string $route, ?array $body = null, ?string $token = null): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        if ($token !== null) {
            $request->set_header('Authorization', 'Bearer '.$token);
        }
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return $request;
    }

    private function count_action(string $action): int
    {
        return AuditLog::count(['action' => $action]);
    }

    private function latest_for(string $action): ?object
    {
        global $wpdb;
        $table = AuditLog::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT 1", $action)
        );
    }

    // =====================================================================
    // Schema + model
    // =====================================================================

    public function test_audit_logs_table_created(): void
    {
        global $wpdb;
        $existing = $wpdb->get_col('SHOW TABLES');

        $this->assertContains($wpdb->prefix.'escalated_audit_logs', $existing);
    }

    public function test_record_persists_actor_action_and_request_context(): void
    {
        wp_set_current_user($this->admin_id);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Audit Agent';

        $id = AuditLog::record('demo.action', 'Widget', 42, ['name' => 'old'], ['name' => 'new']);
        $this->assertIsInt($id);

        $row = AuditLog::find($id);
        $this->assertSame($this->admin_id, (int) $row->user_id);
        $this->assertSame('demo.action', $row->action);
        $this->assertSame('Widget', $row->auditable_type);
        $this->assertSame(42, (int) $row->auditable_id);
        $this->assertSame(['name' => 'old'], json_decode($row->old_values, true));
        $this->assertSame(['name' => 'new'], json_decode($row->new_values, true));
        $this->assertSame('203.0.113.9', $row->ip_address);
        $this->assertSame('PHPUnit Audit Agent', $row->user_agent);
        $this->assertNotEmpty($row->created_at);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function test_record_actor_defaults_to_null_for_system_events(): void
    {
        wp_set_current_user(0);
        $id = AuditLog::record('system.event');
        $row = AuditLog::find($id);

        $this->assertNull($row->user_id);
        $this->assertNull($row->auditable_type);
        $this->assertNull($row->old_values);
        $this->assertNull($row->new_values);
    }

    public function test_all_filters_and_orders_newest_first(): void
    {
        wp_set_current_user($this->admin_id);
        $other = $this->factory->user->create(['role' => 'escalated_agent']);

        $first = AuditLog::record('alpha.one', 'Widget', 1);
        AuditLog::record('beta.two', 'Gadget', 2);
        wp_set_current_user($other);
        $third = AuditLog::record('alpha.one', 'Widget', 3);
        wp_set_current_user($this->admin_id);

        // Newest first.
        $all = AuditLog::all();
        $this->assertSame($third, (int) $all[0]->id);

        // Filter by action.
        $alphas = AuditLog::all(['action' => 'alpha.one']);
        $this->assertCount(2, $alphas);

        // Filter by user.
        $by_other = AuditLog::all(['user_id' => $other]);
        $this->assertCount(1, $by_other);
        $this->assertSame($third, (int) $by_other[0]->id);

        // Filter by auditable type.
        $this->assertCount(1, AuditLog::all(['auditable_type' => 'Gadget']));

        $this->assertSame(3, AuditLog::count());
        $this->assertContains('alpha.one', AuditLog::distinct_actions());
        $this->assertContains('Gadget', AuditLog::distinct_types());
        $this->assertGreaterThan(0, $first);
    }

    // =====================================================================
    // Mutation site: settings + webhooks
    // =====================================================================

    public function test_settings_update_writes_audit_row(): void
    {
        wp_set_current_user($this->admin_id);

        (new Admin_Settings)->persist([
            'ticket_reference_prefix' => 'HELP',
            'default_priority' => 'high',
        ]);

        $row = $this->latest_for('settings.updated');
        $this->assertNotNull($row);
        $this->assertSame('Settings', $row->auditable_type);
        $this->assertSame($this->admin_id, (int) $row->user_id);

        $new = json_decode($row->new_values, true);
        $this->assertSame('HELP', $new['ticket_reference_prefix']);
        $this->assertSame('high', $new['default_priority']);

        $old = json_decode($row->old_values, true);
        $this->assertSame('ESC', $old['ticket_reference_prefix']);
    }

    public function test_webhook_change_is_audited_with_redacted_secret(): void
    {
        wp_set_current_user($this->admin_id);

        (new Admin_Settings)->persist([
            'webhook_url' => 'https://example.com/hook',
            'webhook_secret' => 'super-secret-value',
        ]);

        $row = $this->latest_for('settings.updated');
        $this->assertNotNull($row);

        $new = json_decode($row->new_values, true);
        $this->assertSame('https://example.com/hook', $new['webhook_url']);
        // Secret is redacted, never stored in plaintext in the audit trail.
        $this->assertSame('********', $new['webhook_secret']);
        $this->assertStringNotContainsString('super-secret-value', (string) $row->new_values);
    }

    public function test_settings_persist_records_only_on_change(): void
    {
        wp_set_current_user($this->admin_id);

        // First save settles the form state (e.g. absent checkboxes -> 0) and
        // records one entry for what changed.
        (new Admin_Settings)->persist(['ticket_reference_prefix' => 'ESC']);
        $after_first = $this->count_action('settings.updated');
        $this->assertSame(1, $after_first);

        // Re-submitting the identical payload changes nothing, so no new row.
        (new Admin_Settings)->persist(['ticket_reference_prefix' => 'ESC']);
        $this->assertSame($after_first, $this->count_action('settings.updated'));
    }

    // =====================================================================
    // Mutation site: users / roles
    // =====================================================================

    public function test_role_grant_and_revoke_are_audited(): void
    {
        $target = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($this->admin_id);

        $granted = Admin_Users::update_role($target, 'agent', true, $this->admin_id);
        $this->assertTrue($granted['ok']);

        $grant_row = $this->latest_for('user.role_granted');
        $this->assertNotNull($grant_row);
        $this->assertSame('User', $grant_row->auditable_type);
        $this->assertSame($target, (int) $grant_row->auditable_id);
        $this->assertSame($this->admin_id, (int) $grant_row->user_id);
        $this->assertSame(['role' => 'agent', 'value' => true], json_decode($grant_row->new_values, true));

        $revoked = Admin_Users::update_role($target, 'agent', false, $this->admin_id);
        $this->assertTrue($revoked['ok']);
        $this->assertSame(1, $this->count_action('user.role_revoked'));
    }

    // =====================================================================
    // Mutation site: knowledge base CRUD (REST, cookie auth)
    // =====================================================================

    public function test_kb_article_crud_is_audited(): void
    {
        wp_set_current_user($this->admin_id);

        $created = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/articles', [
            'title' => 'Audited article',
            'status' => 'published',
        ]));
        $this->assertSame(201, $created->get_status());
        $id = (int) $created->get_data()['id'];

        $create_row = $this->latest_for('kb_article.created');
        $this->assertNotNull($create_row);
        $this->assertSame('Article', $create_row->auditable_type);
        $this->assertSame($id, (int) $create_row->auditable_id);

        $updated = $this->server->dispatch($this->json_request('PATCH', '/escalated/v1/admin/kb/articles/'.$id, [
            'title' => 'Audited article v2',
            'status' => 'published',
        ]));
        $this->assertSame(200, $updated->get_status());
        $this->assertSame(1, $this->count_action('kb_article.updated'));

        $deleted = $this->server->dispatch(new WP_REST_Request('DELETE', '/escalated/v1/admin/kb/articles/'.$id));
        $this->assertSame(204, $deleted->get_status());
        $this->assertSame(1, $this->count_action('kb_article.deleted'));
    }

    public function test_kb_category_crud_is_audited(): void
    {
        wp_set_current_user($this->admin_id);

        $created = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/categories', [
            'name' => 'Billing',
        ]));
        $this->assertSame(201, $created->get_status());
        $id = (int) $created->get_data()['id'];
        $this->assertSame(1, $this->count_action('kb_category.created'));

        $updated = $this->server->dispatch($this->json_request('PATCH', '/escalated/v1/admin/kb/categories/'.$id, [
            'name' => 'Billing & Payments',
        ]));
        $this->assertSame(200, $updated->get_status());
        $this->assertSame(1, $this->count_action('kb_category.updated'));

        $deleted = $this->server->dispatch(new WP_REST_Request('DELETE', '/escalated/v1/admin/kb/categories/'.$id));
        $this->assertSame(204, $deleted->get_status());
        $this->assertSame(1, $this->count_action('kb_category.deleted'));
    }

    // =====================================================================
    // Mutation site: API tokens (REST, bearer auth)
    // =====================================================================

    public function test_api_token_create_and_delete_are_audited(): void
    {
        $bearer = ApiToken::create_token($this->admin_id, 'audit-runner', ['*'])['token'];

        $created = $this->server->dispatch($this->json_request(
            'POST',
            '/escalated/v1/admin/api-tokens',
            ['name' => 'CI token', 'user_id' => $this->admin_id],
            $bearer
        ));
        $this->assertSame(201, $created->get_status());
        $new_id = (int) $created->get_data()['token']['id'];

        $create_row = $this->latest_for('api_token.created');
        $this->assertNotNull($create_row);
        $this->assertSame('ApiToken', $create_row->auditable_type);
        $this->assertSame($new_id, (int) $create_row->auditable_id);
        // Bearer requests have no cookie user; the acting token user is recorded.
        $this->assertSame($this->admin_id, (int) $create_row->user_id);

        $deleted = $this->server->dispatch($this->json_request(
            'DELETE',
            '/escalated/v1/admin/api-tokens/'.$new_id,
            null,
            $bearer
        ));
        $this->assertSame(200, $deleted->get_status());
        $this->assertSame(1, $this->count_action('api_token.deleted'));
    }

    // =====================================================================
    // Mutation site: two-factor authentication (REST, bearer auth)
    // =====================================================================

    public function test_two_factor_enable_and_disable_are_audited(): void
    {
        $user_id = $this->factory->user->create(['role' => 'escalated_agent']);
        $bearer = ApiToken::create_token($user_id, '2fa-runner', ['*'])['token'];
        $service = new TwoFactorService;

        $setup = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/two-factor/setup', null, $bearer));
        $this->assertSame(201, $setup->get_status());
        $secret = $setup->get_data()['secret'];

        $code = $service->generate_totp($secret, (int) floor(time() / 30));
        $confirm = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/two-factor/confirm', ['code' => $code], $bearer));
        $this->assertSame(200, $confirm->get_status());

        $enable_row = $this->latest_for('two_factor.enabled');
        $this->assertNotNull($enable_row);
        $this->assertSame('User', $enable_row->auditable_type);
        $this->assertSame($user_id, (int) $enable_row->auditable_id);
        $this->assertSame($user_id, (int) $enable_row->user_id);

        $disable = $this->server->dispatch($this->json_request('DELETE', '/escalated/v1/admin/two-factor', null, $bearer));
        $this->assertSame(200, $disable->get_status());
        $this->assertSame(1, $this->count_action('two_factor.disabled'));
    }

    // =====================================================================
    // Admin list / filter REST surface
    // =====================================================================

    public function test_index_requires_authentication(): void
    {
        wp_set_current_user(0);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs'));
        $this->assertSame(401, $response->get_status());
    }

    public function test_index_forbidden_without_audit_capability(): void
    {
        $light = $this->factory->user->create(['role' => 'escalated_light_agent']);
        wp_set_current_user($light);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs'));
        $this->assertSame(403, $response->get_status());
    }

    public function test_agent_with_audit_view_can_list(): void
    {
        // The escalated_agent role is seeded with the audit.view capability.
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        wp_set_current_user($agent);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs'));
        $this->assertSame(200, $response->get_status());
    }

    public function test_index_returns_seeded_rows_and_metadata(): void
    {
        wp_set_current_user($this->admin_id);
        AuditLog::record('seed.one', 'Widget', 1);
        AuditLog::record('seed.two', 'Gadget', 2);

        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs'));
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame(2, $data['total']);
        $this->assertCount(2, $data['logs']);
        $this->assertArrayHasKey('actions', $data);
        $this->assertArrayHasKey('resource_types', $data);
        $this->assertContains('seed.one', $data['actions']);
        $this->assertContains('Gadget', $data['resource_types']);

        // Newest first, and the actor is expanded.
        $this->assertSame('seed.two', $data['logs'][0]['action']);
        $this->assertSame($this->admin_id, $data['logs'][0]['user']['id']);
    }

    public function test_index_filters_by_action(): void
    {
        wp_set_current_user($this->admin_id);
        AuditLog::record('keep.this', 'Widget', 1);
        AuditLog::record('drop.this', 'Widget', 2);

        $request = new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs');
        $request->set_param('action', 'keep.this');
        $response = $this->server->dispatch($request);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame('keep.this', $data['logs'][0]['action']);
    }

    public function test_index_filters_by_user_type_and_date(): void
    {
        $other = $this->factory->user->create(['role' => 'escalated_agent']);

        wp_set_current_user($this->admin_id);
        AuditLog::record('by.admin', 'Widget', 1);
        wp_set_current_user($other);
        AuditLog::record('by.other', 'Gadget', 2);
        wp_set_current_user($this->admin_id);

        // Filter by user.
        $req = new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs');
        $req->set_param('user_id', $other);
        $data = $this->server->dispatch($req)->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame('by.other', $data['logs'][0]['action']);

        // Filter by auditable type.
        $req = new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs');
        $req->set_param('auditable_type', 'Widget');
        $data = $this->server->dispatch($req)->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame('by.admin', $data['logs'][0]['action']);

        // Date range that includes today returns rows...
        $today = current_time('Y-m-d');
        $req = new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs');
        $req->set_param('date_from', $today);
        $req->set_param('date_to', $today);
        $data = $this->server->dispatch($req)->get_data();
        $this->assertSame(2, $data['total']);

        // ...a window ending yesterday excludes them.
        $yesterday = gmdate('Y-m-d', strtotime($today.' -1 day'));
        $req = new WP_REST_Request('GET', '/escalated/v1/admin/audit-logs');
        $req->set_param('date_to', $yesterday);
        $data = $this->server->dispatch($req)->get_data();
        $this->assertSame(0, $data['total']);
    }
}
