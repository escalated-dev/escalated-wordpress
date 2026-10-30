<?php

/**
 * Roles & permissions (ADR 2026-10-29-configurable-access-features, port
 * checklist "Switches" and "Roles & permissions").
 *
 * On WordPress the permissions are capabilities (`ticket.view` is
 * `escalated_ticket_view`) and the roles are WordPress roles, so the switch
 * decides whether every staff REST route and every ticket action also needs
 * its own capability (on), or whether the routes keep the checks they always
 * had (off).
 */

use Escalated\Admin\Admin_Menu;
use Escalated\Admin\Admin_Roles;
use Escalated\Admin\Admin_Tickets;
use Escalated\Admin\Admin_Users;
use Escalated\Models\Setting;
use Escalated\Support\AccessEnforcer;
use Escalated\Support\AccessFeatures;
use Escalated\Support\RoleCatalog;
use Escalated\Support\RoutePermissions;

class Test_Roles_Permissions extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    private int $admin_id;

    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        $this->boot_rest();
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_all_filters('escalated_access_roles_permissions');
        remove_all_filters('wp_redirect');
        $_POST = [];
        parent::tear_down();
    }

    private function boot_rest(): void
    {
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    private function switch_on(bool $on = true): void
    {
        add_filter('escalated_access_roles_permissions', $on ? '__return_true' : '__return_false');
    }

    private function token_for(int $user_id): string
    {
        $token = wp_generate_password(64, false);
        global $wpdb;
        $wpdb->insert(\Escalated\Escalated::table('api_tokens'), [
            'user_id' => $user_id,
            'name' => 'Test '.$user_id,
            'token' => hash('sha256', $token),
            'abilities' => wp_json_encode(['*']),
            'created_at' => current_time('mysql'),
        ]);

        return $token;
    }

    private function call(string $token, string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/escalated/v1/'.$route);
        $request->set_header('Authorization', 'Bearer '.$token);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }

        return $this->server->dispatch($request);
    }

    private function ticket(array $overrides = []): object
    {
        return (new \Escalated\Services\TicketService)->create($this->admin_id, array_merge([
            'subject' => 'Roles ticket',
            'description' => 'For the permission checks.',
            'priority' => 'medium',
        ], $overrides));
    }

    /** A user whose only role holds exactly these permissions. */
    private function user_with(array $permissions): int
    {
        $slug = RoleCatalog::save(null, 'Custom '.wp_generate_password(6, false), $permissions);

        return $this->factory->user->create(['role' => $slug]);
    }

    /** Run a screen POST handler, stopping at its redirect. */
    private function post_screen(array $post, callable $handler): string
    {
        $_POST = wp_slash($post);
        add_filter('wp_redirect', static function ($location) {
            throw new RuntimeException('redirect:'.$location);
        });
        try {
            $handler();
            $this->fail('the handler did not redirect');
        } catch (RuntimeException $e) {
            return substr($e->getMessage(), strlen('redirect:'));
        } finally {
            $_POST = [];
            remove_all_filters('wp_redirect');
        }

        return '';
    }

    // ---------------------------------------------------------------- switches

    public function test_switch_is_off_by_default_and_hides_the_roles_screens(): void
    {
        $this->assertFalse(AccessFeatures::roles_permissions());
        $this->assertSame(['roles_permissions' => false, 'two_factor' => false, 'sso' => false], AccessFeatures::shared());

        wp_set_current_user($this->admin_id);
        global $submenu;
        $submenu = [];
        (new Admin_Menu)->add_menus();
        $this->assertNotContains('escalated-roles', array_column($submenu['escalated'] ?? [], 2));

        try {
            (new Admin_Roles)->render();
            $this->fail('the roles screen rendered with the switch off');
        } catch (WPDieException $e) {
            $this->assertSame(404, $e->getCode());
        }

        $_POST = ['escalated_role_action' => 'create', 'name' => 'X'];
        try {
            (new Admin_Roles)->handle_actions();
            $this->fail('a role was saved with the switch off');
        } catch (WPDieException $e) {
            $this->assertSame(404, $e->getCode());
        }

        $_POST = ['escalated_user_action' => 'update_roles', 'user_id' => $this->admin_id];
        try {
            (new Admin_Users)->handle_actions();
            $this->fail('roles were assigned with the switch off');
        } catch (WPDieException $e) {
            $this->assertSame(404, $e->getCode());
        }

        // Staff get ['*'], everyone else nothing.
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        $customer = $this->factory->user->create(['role' => 'subscriber']);
        $this->assertSame(['*'], AccessEnforcer::permissions($agent));
        $this->assertSame([], AccessEnforcer::permissions($customer));
    }

    public function test_switch_on_shows_and_serves_the_roles_screens(): void
    {
        $this->switch_on();
        $this->assertTrue(AccessFeatures::shared()['roles_permissions']);

        wp_set_current_user($this->admin_id);
        global $submenu;
        $submenu = [];
        (new Admin_Menu)->add_menus();
        $this->assertContains('escalated-roles', array_column($submenu['escalated'] ?? [], 2));

        ob_start();
        (new Admin_Roles)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString('escalated_light_agent', $html);

        // Create, edit and delete a role; system roles cannot be deleted.
        $created = Admin_Roles::apply('create', '', ['name' => 'Billing desk', 'permissions' => ['ticket.view', 'reply.create']]);
        $this->assertTrue($created['ok']);
        $slug = $created['role'];
        $this->assertSame(['ticket.view', 'reply.create'], RoleCatalog::roles()[$slug]['permissions']);

        $this->assertTrue(Admin_Roles::apply('update', $slug, ['name' => 'Billing', 'permissions' => ['ticket.view']])['ok']);
        $this->assertSame('Billing', RoleCatalog::roles()[$slug]['name']);
        $this->assertSame(['ticket.view'], RoleCatalog::roles()[$slug]['permissions']);

        $this->assertSame('permissions', Admin_Roles::apply('create', '', ['name' => 'Bad', 'permissions' => ['nope.nope']])['error']);
        $this->assertSame('name', Admin_Roles::apply('create', '', ['name' => '', 'permissions' => []])['error']);
        $this->assertSame('system_role', Admin_Roles::apply('delete', 'escalated_agent', [])['error']);
        $this->assertNotNull(get_role('escalated_agent'));
        $this->assertTrue(Admin_Roles::apply('delete', $slug, [])['ok']);
        $this->assertNull(get_role($slug));

        // Through the screen's POST handler.
        wp_set_current_user($this->admin_id);
        $location = $this->post_screen([
            'escalated_role_action' => 'create',
            '_escalated_nonce' => wp_create_nonce('escalated_role_create'),
            'name' => 'Night shift',
            'permissions' => ['ticket.view'],
        ], [new Admin_Roles, 'handle_actions']);
        $this->assertStringContainsString('message=created', $location);
        $this->assertNotNull(get_role('escalated_night_shift'));

        // A user without role.manage cannot change roles.
        wp_set_current_user($this->factory->user->create(['role' => 'escalated_agent']));
        $_POST = ['escalated_role_action' => 'create', 'name' => 'X'];
        try {
            (new Admin_Roles)->handle_actions();
            $this->fail('an agent saved a role');
        } catch (WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_users_screen_assigns_escalated_roles(): void
    {
        $this->switch_on();
        $user = $this->factory->user->create(['role' => 'subscriber']);

        $this->assertTrue(Admin_Users::update_roles($user, ['escalated_light_agent'], $this->admin_id)['ok']);
        $this->assertSame(['escalated_light_agent'], RoleCatalog::user_roles($user));
        $this->assertContains('subscriber', get_userdata($user)->roles, 'other roles are kept');

        $this->assertSame('invalid_role', Admin_Users::update_roles($user, ['administrator'], $this->admin_id)['error']);

        // You cannot remove your own role management.
        $manager = $this->factory->user->create(['role' => 'escalated_admin']);
        $this->assertSame('self_role_manage', Admin_Users::update_roles($manager, ['escalated_agent'], $manager)['error']);
        $this->assertSame(['escalated_admin'], RoleCatalog::user_roles($manager));

        // The screen lists each user's roles.
        wp_set_current_user($this->admin_id);
        ob_start();
        (new Admin_Users)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString('name="roles[]"', $html);
    }

    public function test_switch_is_config_or_env_only(): void
    {
        // A stored setting with the switch's name changes nothing.
        Setting::set('roles_permissions', '1');
        update_option('escalated_roles_permissions', '1');
        $this->assertFalse(AccessFeatures::roles_permissions());

        putenv('ESCALATED_ROLES_PERMISSIONS=true');
        try {
            $this->assertTrue(AccessFeatures::roles_permissions());
        } finally {
            putenv('ESCALATED_ROLES_PERMISSIONS');
        }
        $this->assertFalse(AccessFeatures::roles_permissions());
    }

    // ---------------------------------------------------------- enforcement

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    private function gated_requests(object $ticket): array
    {
        return [
            'admin read' => ['GET', 'automations', []],
            'admin write' => ['POST', 'automations', ['name' => 'Close stale', 'conditions' => [], 'actions' => []]],
            'agent ticket action' => ['POST', 'tickets/'.$ticket->reference.'/priority', ['priority' => 'low']],
        ];
    }

    public function test_a_permission_is_needed_with_the_switch_on_and_a_role_grants_it(): void
    {
        $this->switch_on();
        $ticket = $this->ticket();
        // Staff (ticket.view) but none of these permissions.
        $user = $this->user_with(['ticket.view']);
        $token = $this->token_for($user);

        foreach ($this->gated_requests($ticket) as $what => [$method, $route, $params]) {
            $this->assertSame(403, $this->call($token, $method, $route, $params)->get_status(), $what.' without the permission');
        }

        $granted = $this->user_with(['ticket.view', 'automation.view', 'automation.manage', 'ticket.edit']);
        $granted_token = $this->token_for($granted);
        foreach ($this->gated_requests($ticket) as $what => [$method, $route, $params]) {
            $this->assertLessThan(400, $this->call($granted_token, $method, $route, $params)->get_status(), $what.' with the permission');
        }

        $this->assertSame(['ticket.view'], AccessEnforcer::permissions($user));
    }

    public function test_with_the_switch_off_the_same_requests_pass_the_gates_alone(): void
    {
        $ticket = $this->ticket();
        $token = $this->token_for($this->user_with(['ticket.view']));
        foreach ($this->gated_requests($ticket) as $what => [$method, $route, $params]) {
            $this->assertLessThan(400, $this->call($token, $method, $route, $params)->get_status(), $what.' with the switch off');
        }
    }

    public function test_light_agent_can_view_and_note_but_not_assign_reprioritise_close_or_delete(): void
    {
        $this->switch_on();
        $ticket = $this->ticket();
        $light = $this->factory->user->create(['role' => 'escalated_light_agent']);
        $token = $this->token_for($light);
        $ref = $ticket->reference;

        $this->assertSame(200, $this->call($token, 'GET', 'tickets/'.$ref)->get_status());
        $this->assertLessThan(400, $this->call($token, 'POST', 'tickets/'.$ref.'/note', ['body' => 'Checked the logs'])->get_status());
        $this->assertSame(403, $this->call($token, 'POST', 'tickets/'.$ref.'/assign', ['agent_id' => $this->admin_id])->get_status());
        $this->assertSame(403, $this->call($token, 'POST', 'tickets/'.$ref.'/priority', ['priority' => 'low'])->get_status());
        $this->assertSame(403, $this->call($token, 'POST', 'tickets/'.$ref.'/status', ['status' => 'closed'])->get_status());
        $this->assertSame(403, $this->call($token, 'DELETE', 'tickets/'.$ref)->get_status());

        // The wp-admin ticket screen follows the same rules.
        wp_set_current_user($light);
        foreach (['change_priority' => ['new_priority' => 'low'], 'change_status' => ['new_status' => 'closed'], 'assign' => ['assigned_to' => $this->admin_id]] as $action => $extra) {
            $_POST = wp_slash(array_merge([
                'escalated_ticket_action' => $action,
                'ticket_id' => $ticket->id,
                '_escalated_nonce' => wp_create_nonce('escalated_ticket_action_'.$ticket->id),
            ], $extra));
            try {
                (new Admin_Tickets)->handle_actions();
                $this->fail('light agent '.$action.' on the ticket screen');
            } catch (WPDieException $e) {
                $this->assertSame(403, $e->getCode(), $action);
            }
        }
        $location = $this->post_screen([
            'escalated_ticket_action' => 'note',
            'ticket_id' => $ticket->id,
            '_escalated_nonce' => wp_create_nonce('escalated_ticket_action_'.$ticket->id),
            'note_body' => 'Internal',
        ], [new Admin_Tickets, 'handle_actions']);
        $this->assertStringContainsString('note_added', $location);
    }

    public function test_light_agent_ticket_screen_with_the_switch_off_keeps_its_old_checks(): void
    {
        $ticket = $this->ticket();
        wp_set_current_user($this->factory->user->create(['role' => 'escalated_light_agent']));
        $location = $this->post_screen([
            'escalated_ticket_action' => 'change_priority',
            'ticket_id' => $ticket->id,
            '_escalated_nonce' => wp_create_nonce('escalated_ticket_action_'.$ticket->id),
            'new_priority' => 'low',
        ], [new Admin_Tickets, 'handle_actions']);
        $this->assertStringContainsString('priority_changed', $location);
    }

    public function test_closing_statuses_need_ticket_close(): void
    {
        $this->switch_on();
        $token = $this->token_for($this->user_with(['ticket.view', 'ticket.edit']));

        foreach (['closed', 'resolved'] as $status) {
            $ticket = $this->ticket();
            $this->assertSame(403, $this->call($token, 'POST', 'tickets/'.$ticket->reference.'/status', ['status' => $status])->get_status(), $status);
        }
        $closed = $this->ticket();
        \Escalated\Models\Ticket::update($closed->id, ['status' => 'closed']);
        $this->assertSame(403, $this->call($token, 'POST', 'tickets/'.$closed->reference.'/status', ['status' => 'reopened'])->get_status(), 'reopened');

        $ticket = $this->ticket();
        $this->assertLessThan(400, $this->call($token, 'POST', 'tickets/'.$ticket->reference.'/status', ['status' => 'in_progress'])->get_status());

        $closer = $this->token_for($this->user_with(['ticket.view', 'ticket.edit', 'ticket.close']));
        $this->assertLessThan(400, $this->call($closer, 'POST', 'tickets/'.$ticket->reference.'/status', ['status' => 'resolved'])->get_status());
    }

    public function test_the_staff_api_checks_the_token_owner(): void
    {
        $this->switch_on();
        $owner = $this->factory->user->create(['role' => 'escalated_agent']);
        $token = $this->token_for($owner);
        $this->assertSame(200, $this->call($token, 'GET', 'tickets')->get_status());

        // The owner's roles change: the token follows them.
        $user = new WP_User($owner);
        $user->set_role('subscriber');
        clean_user_cache($owner);
        $this->assertSame(403, $this->call($token, 'GET', 'tickets')->get_status());
    }

    public function test_passing_the_admin_gate_without_a_role_grants_nothing(): void
    {
        $this->switch_on();
        // manage_options passes this plugin's admin gate (TicketAccess::is_admin),
        // but the role holds no Escalated capability.
        add_role('site_manager', 'Site manager', ['read' => true, 'manage_options' => true]);
        $manager = $this->factory->user->create(['role' => 'site_manager']);
        $this->assertTrue(\Escalated\Services\TicketAccess::is_admin($manager));
        $this->assertSame(403, $this->call($this->token_for($manager), 'GET', 'tickets')->get_status());
        $this->assertSame([], AccessEnforcer::permissions($manager));
        remove_role('site_manager');
    }

    public function test_every_staff_route_is_in_the_permission_map(): void
    {
        update_option(\Escalated\Services\Newsletter\NewsletterConfig::OPTION_ENABLED, '1');
        $this->boot_rest();
        $checked = 0;
        foreach ($this->server->get_routes('escalated/v1') as $route => $handlers) {
            $path = RoutePermissions::normalize($route);
            if (RoutePermissions::is_public($path)) {
                continue;
            }
            foreach ($handlers as $handler) {
                foreach (array_keys($handler['methods']) as $method) {
                    $checked++;
                    $this->assertNotNull(RoutePermissions::mapped($method, $path), "{$method} {$route} is not in RoutePermissions");
                }
            }
        }
        $this->assertGreaterThan(60, $checked);
        foreach (['/auth/login', '/widget/config', '/guest/lookup', '/mobile/guest/tickets', '/inbound/{adapter}'] as $public) {
            $this->assertTrue(RoutePermissions::is_public($public), $public);
        }
    }

    public function test_an_unmapped_staff_route_is_refused(): void
    {
        $this->switch_on();
        add_action('rest_api_init', static function () {
            register_rest_route('escalated/v1', '/unmapped-thing', [
                'methods' => 'GET',
                'callback' => static fn () => new WP_REST_Response(['ok' => true]),
                'permission_callback' => '__return_true',
            ]);
        });
        $this->boot_rest();
        wp_set_current_user($this->admin_id);
        $this->assertSame(403, $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/unmapped-thing'))->get_status());
    }
}
