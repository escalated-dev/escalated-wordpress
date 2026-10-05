<?php

/**
 * Per-client-IP rate limit on the unauthenticated guest endpoints.
 *
 * Every accepted guest ticket or reply writes rows and sends outbound mail, so
 * the plugin itself caps them per client IP (5 ticket submissions and 10
 * replies per minute by default) instead of relying on the host site to put a
 * throttle in front. Mirrors escalated-dev/escalated-nestjs#130.
 */

use Escalated\Models\Setting;
use Escalated\Services\GuestRateLimiter;

class Test_Guest_Rate_Limit extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    private ?string $previous_ip;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');

        // A fresh address per test, so no counter leaks between tests.
        $this->previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.'.wp_rand(1, 254);
        $this->clear_counters($_SERVER['REMOTE_ADDR']);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        $this->clear_counters($_SERVER['REMOTE_ADDR']);
        if ($this->previous_ip === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previous_ip;
        }

        parent::tear_down();
    }

    private function clear_counters(string $ip): void
    {
        delete_transient('escalated_widget_rate_'.md5($ip));
        foreach (['ticket', 'reply'] as $scope) {
            delete_transient(GuestRateLimiter::key($scope, $ip));
        }
    }

    private function widget_ticket(int $n): WP_REST_Response
    {
        // A distinct email per call, so only the per-IP limit can be what trips.
        $request = new WP_REST_Request('POST', '/escalated/v1/widget/tickets');
        $request->set_param('name', 'Guest '.$n);
        $request->set_param('email', 'guest'.$n.'@example.com');
        $request->set_param('subject', 'Help '.$n);
        $request->set_param('description', 'Something broke.');

        return $this->server->dispatch($request);
    }

    /** @return int[] */
    private function widget_statuses(int $times): array
    {
        $out = [];
        for ($i = 0; $i < $times; $i++) {
            $out[] = $this->widget_ticket($i)->get_status();
        }

        return $out;
    }

    // =========================================================================
    // Widget REST ticket endpoint
    // =========================================================================

    public function test_sixth_widget_ticket_from_one_ip_within_a_minute_gets_429(): void
    {
        $this->assertSame([201, 201, 201, 201, 201, 429], $this->widget_statuses(6));
    }

    public function test_rejected_widget_ticket_carries_retry_after(): void
    {
        Setting::set('guest_rate_limit_tickets_per_minute', '1');
        $this->widget_ticket(1);

        $response = $this->widget_ticket(2);

        $this->assertSame(429, $response->get_status());
        $headers = $response->get_headers();
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertGreaterThan(0, (int) $headers['Retry-After']);
        $this->assertLessThanOrEqual(60, (int) $headers['Retry-After']);
    }

    public function test_rejected_widget_ticket_creates_nothing(): void
    {
        Setting::set('guest_rate_limit_tickets_per_minute', '1');
        $this->widget_ticket(1);
        $before = $this->ticket_count();

        $this->widget_ticket(2);

        $this->assertSame($before, $this->ticket_count());
    }

    public function test_widget_ticket_limit_comes_from_settings(): void
    {
        Setting::set('guest_rate_limit_tickets_per_minute', '2');

        $this->assertSame([201, 201, 429], $this->widget_statuses(3));
    }

    public function test_widget_ticket_limit_can_be_switched_off(): void
    {
        Setting::set('guest_rate_limit_enabled', '0');

        $statuses = $this->widget_statuses(8);

        $this->assertSame(array_fill(0, 8, 201), $statuses);
    }

    public function test_widget_ticket_limit_is_per_ip(): void
    {
        $this->widget_statuses(5);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->clear_counters('198.51.100.7');

        $this->assertSame(201, $this->widget_ticket(99)->get_status());
        $this->clear_counters('198.51.100.7');
    }

    private function ticket_count(): int
    {
        $wpdb = \Escalated\Escalated::db();

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM '.\Escalated\Models\Ticket::table());
    }

    // =========================================================================
    // Limiter
    // =========================================================================

    public function test_ticket_and_reply_counters_are_separate(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(GuestRateLimiter::attempt('ticket', $ip));
        }

        $this->assertNotNull(GuestRateLimiter::attempt('ticket', $ip));
        $this->assertNull(GuestRateLimiter::attempt('reply', $ip));
    }

    public function test_reply_default_allows_ten_per_minute(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull(GuestRateLimiter::attempt('reply', $ip));
        }

        $retry_after = GuestRateLimiter::attempt('reply', $ip);

        $this->assertIsInt($retry_after);
        $this->assertGreaterThan(0, $retry_after);
        $this->assertLessThanOrEqual(60, $retry_after);
    }

    public function test_unset_limit_keeps_its_default(): void
    {
        Setting::set('guest_rate_limit_replies_per_minute', '1');

        $this->assertSame(5, GuestRateLimiter::config()['tickets_per_minute']);
        $this->assertSame(1, GuestRateLimiter::config()['replies_per_minute']);
        $this->assertTrue(GuestRateLimiter::config()['enabled']);
    }

    public function test_hosts_can_override_the_config_with_a_filter(): void
    {
        $filter = static function (array $config): array {
            $config['tickets_per_minute'] = 1;

            return $config;
        };
        add_filter('escalated_guest_rate_limit', $filter);

        try {
            $this->assertSame([201, 429], $this->widget_statuses(2));
        } finally {
            remove_filter('escalated_guest_rate_limit', $filter);
        }
    }

    public function test_counter_expires_with_the_window(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        Setting::set('guest_rate_limit_tickets_per_minute', '1');
        GuestRateLimiter::attempt('ticket', $ip);

        // Pretend the minute has elapsed.
        set_transient(GuestRateLimiter::key('ticket', $ip), ['count' => 1, 'reset' => time() - 1], 60);

        $this->assertNull(GuestRateLimiter::attempt('ticket', $ip));
    }
}
