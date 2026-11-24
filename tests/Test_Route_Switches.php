<?php

/**
 * Optional public surfaces are registered only while switched on
 * (ESCALATED_WIDGET_ENABLED, ESCALATED_GUEST_ROUTES), and the customer portal
 * can require capabilities (ESCALATED_CUSTOMER_CAPABILITIES). Defaults keep
 * everything as it was.
 */

use Escalated\Api\Api_Bootstrap;
use Escalated\Frontend\Ajax_Handler;
use Escalated\Frontend\Shortcodes;
use Escalated\Models\Setting;
use Escalated\Support\RouteSwitches;

class Test_Route_Switches extends WP_UnitTestCase
{
    private const WIDGET_ROUTES = [
        '/escalated/v1/widget/config',
        '/escalated/v1/widget/tickets',
        '/escalated/v1/widget/chat/start',
        '/escalated/v1/widget/embed\.js',
    ];

    private const GUEST_ROUTES = [
        '/escalated/v1/guest/verification',
        '/escalated/v1/guest/lookup',
        '/escalated/v1/mobile/guest/tickets',
    ];

    private const ENV = ['ESCALATED_WIDGET_ENABLED', 'ESCALATED_GUEST_ROUTES', 'ESCALATED_CUSTOMER_CAPABILITIES'];

    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
        Setting::set('guest_tickets_enabled', '1');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        foreach (self::ENV as $name) {
            putenv($name);
        }
        $_GET = [];
        wp_set_current_user(0);
        parent::tear_down();
    }

    /** @return list<string> */
    private function routes(): array
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server;
        remove_all_actions('rest_api_init');
        add_action('rest_api_init', [new Api_Bootstrap, 'register_routes']);
        do_action('rest_api_init', $wp_rest_server);

        return array_keys($wp_rest_server->get_routes('escalated/v1'));
    }

    public function test_by_default_the_widget_and_guest_routes_are_registered(): void
    {
        $this->assertTrue(RouteSwitches::widget_enabled());
        $this->assertTrue(RouteSwitches::guest_routes_enabled());

        $routes = $this->routes();
        foreach (array_merge(self::WIDGET_ROUTES, self::GUEST_ROUTES) as $route) {
            $this->assertContains($route, $routes);
        }

        remove_all_actions('wp_ajax_nopriv_escalated_guest_create');
        (new Ajax_Handler)->register();
        $this->assertNotFalse(has_action('wp_ajax_nopriv_escalated_guest_create'));
    }

    public function test_widget_off_registers_no_widget_route(): void
    {
        putenv('ESCALATED_WIDGET_ENABLED=false');

        $this->assertFalse(RouteSwitches::widget_enabled());
        $routes = $this->routes();
        $this->assertSame([], array_values(array_filter($routes, static fn ($r) => str_starts_with($r, '/escalated/v1/widget'))));
        // Everything else stays.
        foreach (self::GUEST_ROUTES as $route) {
            $this->assertContains($route, $routes);
        }
        $this->assertContains('/escalated/v1/tickets', $routes);

        // No widget CORS handling either.
        remove_all_filters('rest_pre_dispatch');
        (new Api_Bootstrap)->register();
        $this->assertFalse(has_filter('rest_pre_dispatch'));
    }

    public function test_guest_routes_off_registers_no_guest_route_or_ajax_action(): void
    {
        add_filter('escalated_guest_routes_enabled', '__return_false');

        $routes = $this->routes();
        $this->assertSame([], array_values(array_filter(
            $routes,
            static fn ($r) => str_starts_with($r, '/escalated/v1/guest') || str_starts_with($r, '/escalated/v1/mobile/guest')
        )));
        foreach (self::WIDGET_ROUTES as $route) {
            $this->assertContains($route, $routes);
        }

        foreach (['wp_ajax_nopriv_escalated_guest_create', 'wp_ajax_nopriv_escalated_guest_reply', 'wp_ajax_escalated_guest_create', 'wp_ajax_escalated_guest_reply'] as $hook) {
            remove_all_actions($hook);
        }
        (new Ajax_Handler)->register();
        $this->assertFalse(has_action('wp_ajax_nopriv_escalated_guest_create'));
        $this->assertFalse(has_action('wp_ajax_nopriv_escalated_guest_reply'));
        $this->assertNotFalse(has_action('wp_ajax_escalated_create_ticket'));

        // The guest pages say so instead of working.
        $this->assertStringContainsString('Guest tickets are not available.', (new Shortcodes)->render_guest_create([]));
        $_GET = ['guest_token' => 'ega1.anything'];
        $this->assertStringContainsString('Guest tickets are not available.', (new Shortcodes)->render_view_ticket([]));
    }

    public function test_the_guest_switch_reads_the_environment(): void
    {
        putenv('ESCALATED_GUEST_ROUTES=0');
        $this->assertFalse(RouteSwitches::guest_routes_enabled());

        putenv('ESCALATED_GUEST_ROUTES=true');
        $this->assertTrue(RouteSwitches::guest_routes_enabled());
    }

    public function test_customer_portal_is_open_to_any_signed_in_user_by_default(): void
    {
        $this->assertSame([], RouteSwitches::customer_capabilities());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertTrue(RouteSwitches::customer_allowed());
        $this->assertStringNotContainsString('do not have access', (new Shortcodes)->render_ticket_list([]));
        $this->assertStringNotContainsString('do not have access', (new Shortcodes)->render_create_ticket([]));
    }

    public function test_customer_capabilities_keep_users_without_them_out(): void
    {
        putenv('ESCALATED_CUSTOMER_CAPABILITIES=escalated_ticket_view');
        $this->assertSame(['escalated_ticket_view'], RouteSwitches::customer_capabilities());

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse(RouteSwitches::customer_allowed());
        $shortcodes = new Shortcodes;
        $this->assertStringContainsString('You do not have access to the support portal.', $shortcodes->render_ticket_list([]));
        $this->assertStringContainsString('You do not have access to the support portal.', $shortcodes->render_create_ticket([]));
        $_GET = ['ticket' => 'ESC-1'];
        $this->assertStringContainsString('You do not have access to the support portal.', $shortcodes->render_view_ticket([]));

        wp_set_current_user(self::factory()->user->create(['role' => 'escalated_agent']));
        $this->assertTrue(RouteSwitches::customer_allowed());
        $this->assertStringNotContainsString('do not have access', $shortcodes->render_ticket_list([]));
    }

    public function test_customer_access_takes_a_custom_guard(): void
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $deny = static fn ($allowed, $user_id) => $user_id !== $user && $allowed;
        add_filter('escalated_customer_can_access', $deny, 10, 2);

        $this->assertFalse(RouteSwitches::customer_allowed($user));
        $this->assertTrue(RouteSwitches::customer_allowed(self::factory()->user->create(['role' => 'subscriber'])));
        $this->assertFalse(RouteSwitches::customer_allowed(0));
    }
}
