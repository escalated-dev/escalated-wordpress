<?php

/**
 * The customer portal's admin-ajax actions honour
 * ESCALATED_CUSTOMER_CAPABILITIES like its shortcodes do.
 */

use Escalated\Models\Ticket;

class Test_Customer_Portal_Ajax extends WP_Ajax_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
    }

    public function tear_down(): void
    {
        putenv('ESCALATED_CUSTOMER_CAPABILITIES');
        parent::tear_down();
    }

    public function test_a_user_without_the_customer_capabilities_cannot_raise_a_ticket(): void
    {
        putenv('ESCALATED_CUSTOMER_CAPABILITIES=escalated_ticket_view');
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $response = $this->create_ticket('Staff only');

        $this->assertFalse($response['success']);
        $this->assertSame('You do not have access to the support portal.', $response['data']['message']);
        $this->assertNull($this->find('Staff only'));
    }

    public function test_by_default_any_signed_in_user_can_raise_a_ticket(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $response = $this->create_ticket('Open portal');

        $this->assertTrue($response['success'], (string) wp_json_encode($response));
        $this->assertNotNull($this->find('Open portal'));
    }

    private function create_ticket(string $subject): array
    {
        $_POST = [
            'nonce' => wp_create_nonce('escalated_frontend'),
            'subject' => $subject,
            'description' => 'Body',
        ];
        $this->_last_response = '';

        try {
            $this->_handleAjax('escalated_create_ticket');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_* ends the request through wp_die().
        } catch (WPAjaxDieStopException $e) {
            // Same, for responses that stop.
        }

        return (array) json_decode($this->_last_response, true);
    }

    private function find(string $subject): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT id FROM '.Ticket::table().' WHERE subject = %s', $subject)) ?: null;
    }
}
