<?php

/**
 * Per-client-IP rate limit on the guest AJAX handlers (the [escalated_guest]
 * shortcode's ticket form and guest reply form).
 *
 * The reply throttle runs before the guest token is looked up, so requests
 * carrying a wrong token are counted too and tokens cannot be guessed at speed.
 */

use Escalated\Models\Setting;
use Escalated\Services\GuestRateLimiter;

class Test_Guest_Ajax_Rate_Limit extends WP_Ajax_UnitTestCase
{
    private ?string $previous_ip;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('guest_tickets_enabled', '1');
        wp_set_current_user(0);

        $this->previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '192.0.2.'.wp_rand(1, 254);
        $this->clear_counters();
    }

    public function tear_down(): void
    {
        $this->clear_counters();
        if ($this->previous_ip === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previous_ip;
        }

        parent::tear_down();
    }

    private function clear_counters(): void
    {
        foreach (['ticket', 'reply'] as $scope) {
            delete_transient(GuestRateLimiter::key($scope, $_SERVER['REMOTE_ADDR']));
        }
    }

    /**
     * Run one AJAX action and return its decoded JSON body.
     *
     * @param  array<string, string>  $post
     * @return array<string, mixed>
     */
    private function ajax(string $action, array $post): array
    {
        $_POST = array_merge(['nonce' => wp_create_nonce('escalated_frontend')], $post);
        $this->_last_response = '';

        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request through wp_die().
        }

        return (array) json_decode($this->_last_response, true);
    }

    /** @return array<string, mixed> */
    private function guest_create(int $n): array
    {
        return $this->ajax('escalated_guest_create', [
            'name' => 'Guest '.$n,
            'email' => 'ajax-guest'.$n.'@example.com',
            'subject' => 'Help '.$n,
            'description' => 'Something broke.',
        ]);
    }

    /** @return array<string, mixed> */
    private function guest_reply(string $token): array
    {
        return $this->ajax('escalated_guest_reply', [
            'guest_token' => $token,
            'body' => 'Any update?',
        ]);
    }

    /**
     * Collapse a response to 'ok', 'limited' or the error message.
     *
     * @param  array<string, mixed>  $response
     */
    private function outcome(array $response): string
    {
        if (! empty($response['success'])) {
            return 'ok';
        }

        if (($response['data']['code'] ?? null) === 'escalated_guest_rate_limited') {
            return 'limited';
        }

        return (string) ($response['data']['message'] ?? 'unknown');
    }

    private function guest_ticket_token(): string
    {
        $ticket = (new \Escalated\Services\TicketService)->create_guest([
            'subject' => 'Existing',
            'description' => 'Existing ticket.',
            'guest_name' => 'Existing Guest',
            'guest_email' => 'existing@example.com',
        ]);

        return (string) $ticket->guest_token;
    }

    public function test_sixth_guest_ticket_from_one_ip_within_a_minute_is_limited(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 6; $i++) {
            $outcomes[] = $this->outcome($this->guest_create($i));
        }

        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'limited'], $outcomes);
    }

    public function test_eleventh_guest_reply_from_one_ip_within_a_minute_is_limited(): void
    {
        $token = $this->guest_ticket_token();

        $outcomes = [];
        for ($i = 0; $i < 11; $i++) {
            $outcomes[] = $this->outcome($this->guest_reply($token));
        }

        $this->assertSame(array_merge(array_fill(0, 10, 'ok'), ['limited']), $outcomes);
    }

    public function test_replies_with_a_wrong_guest_token_are_counted(): void
    {
        Setting::set('guest_rate_limit_replies_per_minute', '2');

        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = $this->outcome($this->guest_reply('wrong-token'));
        }

        $this->assertSame(['Ticket not found.', 'Ticket not found.', 'limited'], $outcomes);
    }

    public function test_limited_reply_is_not_stored(): void
    {
        Setting::set('guest_rate_limit_replies_per_minute', '1');
        $token = $this->guest_ticket_token();
        $this->guest_reply($token);
        $wpdb = \Escalated\Escalated::db();
        $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM '.\Escalated\Models\Reply::table());

        $response = $this->guest_reply($token);

        $this->assertSame('limited', $this->outcome($response));
        $this->assertGreaterThan(0, (int) $response['data']['retry_after']);
        $this->assertSame($before, (int) $wpdb->get_var('SELECT COUNT(*) FROM '.\Escalated\Models\Reply::table()));
    }

    public function test_guest_ticket_limit_comes_from_settings(): void
    {
        Setting::set('guest_rate_limit_tickets_per_minute', '2');

        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = $this->outcome($this->guest_create($i));
        }

        $this->assertSame(['ok', 'ok', 'limited'], $outcomes);
    }

    public function test_guest_limits_can_be_switched_off(): void
    {
        Setting::set('guest_rate_limit_enabled', '0');
        $token = $this->guest_ticket_token();

        $outcomes = [];
        for ($i = 0; $i < 8; $i++) {
            $outcomes[] = $this->outcome($this->guest_create($i));
        }
        for ($i = 0; $i < 12; $i++) {
            $outcomes[] = $this->outcome($this->guest_reply($token));
        }

        $this->assertSame(array_fill(0, 20, 'ok'), $outcomes);
    }
}
