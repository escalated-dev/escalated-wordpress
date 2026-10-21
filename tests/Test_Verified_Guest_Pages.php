<?php

/**
 * Verified guest access on the guest pages: the [escalated_guest_create]
 * form's AJAX handler needs a ticket code and answers with an expiring
 * private link, which the guest view and guest reply accept. Legacy
 * permanent links work until the setting is switched off.
 */

use Escalated\Frontend\Shortcodes;
use Escalated\Models\Reply;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;

class Test_Verified_Guest_Pages extends WP_Ajax_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('guest_tickets_enabled', '1');
        wp_set_current_user(0);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.'.wp_rand(1, 254);
        add_filter('escalated_guest_rate_limit', [$this, 'no_guest_throttle']);
    }

    public function tear_down(): void
    {
        remove_filter('escalated_guest_rate_limit', [$this, 'no_guest_throttle']);
        $_GET = [];

        parent::tear_down();
    }

    public function no_guest_throttle(array $config): array
    {
        $config['enabled'] = false;

        return $config;
    }

    public function test_the_form_needs_a_ticket_code(): void
    {
        $response = $this->ajax('escalated_guest_create', $this->form());

        $this->assertFalse($response['success']);
        $this->assertTrue($response['data']['verification_required']);
        $this->assertSame(0, $this->ticket_count());

        $response = $this->ajax('escalated_guest_create', $this->form() + escalated_test_guest_proof('guest@example.com', 'chat'));
        $this->assertFalse($response['success']);
        $this->assertSame(0, $this->ticket_count());
    }

    public function test_the_form_fields_and_code_create_a_ticket_with_an_expiring_link(): void
    {
        // The shortcode form's own field names.
        $post = [
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
            'subject' => 'Help',
            'description' => 'Broken',
        ] + escalated_test_guest_proof('guest@example.com');

        $response = $this->ajax('escalated_guest_create', $post);

        $this->assertTrue($response['success'], (string) wp_json_encode($response));
        $this->assertNotEmpty($response['data']['expires_at']);
        parse_str((string) wp_parse_url($response['data']['view_url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('ega1.', $query['guest_token']);

        $ticket = Ticket::find_by_reference($response['data']['reference']);
        $this->assertSame('Guest', $ticket->guest_name);

        // The link opens the guest view, and its reply form carries the grant.
        $html = $this->view($query['guest_token']);
        $this->assertStringContainsString('Help', $html);
        $this->assertStringContainsString('value="'.esc_attr($query['guest_token']).'"', $html);

        $reply = $this->ajax('escalated_guest_reply', ['guest_token' => $query['guest_token'], 'body' => 'Any news?']);
        $this->assertTrue($reply['success']);
        $this->assertCount(1, Reply::for_ticket((int) $ticket->id, false));
    }

    public function test_an_expired_link_and_a_switched_off_legacy_link_are_refused(): void
    {
        $ticket = (new \Escalated\Services\TicketService)->create_guest([
            'subject' => 'Old', 'description' => 'Old ticket', 'guest_name' => 'Old', 'guest_email' => 'old@example.com',
        ]);
        $grant = \Escalated\Services\GuestAccess::issue($ticket);

        $this->assertStringContainsString('Old ticket', $this->view($ticket->guest_token));
        $this->assertTrue($this->ajax('escalated_guest_reply', ['guest_token' => $ticket->guest_token, 'body' => 'Legacy'])['success']);

        Setting::set('guest_legacy_tokens_enabled', '0');
        $this->assertStringNotContainsString('Old ticket', $this->view($ticket->guest_token));
        $this->assertFalse($this->ajax('escalated_guest_reply', ['guest_token' => $ticket->guest_token, 'body' => 'Legacy'])['success']);
        $this->assertStringContainsString('Old ticket', $this->view($grant['token']));

        Ticket::update((int) $ticket->id, ['guest_access_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->assertStringNotContainsString('Old ticket', $this->view($grant['token']));
        $this->assertFalse($this->ajax('escalated_guest_reply', ['guest_token' => $grant['token'], 'body' => 'Late'])['success']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function form(): array
    {
        return ['name' => 'Guest', 'email' => 'guest@example.com', 'subject' => 'Help', 'description' => 'Broken'];
    }

    private function view(string $token): string
    {
        $_GET = ['guest_token' => $token];

        return (new Shortcodes)->render_view_ticket([]);
    }

    private function ajax(string $action, array $post): array
    {
        $_POST = array_merge(['nonce' => wp_create_nonce('escalated_frontend')], $post);
        $this->_last_response = '';

        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request through wp_die().
        } catch (WPAjaxDieStopException $e) {
            // Same, for responses that stop.
        }

        return (array) json_decode($this->_last_response, true);
    }

    private function ticket_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Ticket::table().' WHERE guest_email = %s', 'guest@example.com'));
    }
}
