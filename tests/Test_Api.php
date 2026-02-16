<?php
/**
 * Tests for the REST API endpoints.
 *
 * Covers authentication, ticket CRUD via API, permission checks,
 * and error responses.
 *
 * @package Escalated
 */

use Escalated\Models\Ticket;
use Escalated\Models\ApiToken;

class Test_Api extends WP_UnitTestCase {

    /**
     * @var int Admin user ID.
     */
    private int $admin_id;

    /**
     * @var string Valid API token.
     */
    private string $token;

    /**
     * @var WP_REST_Server
     */
    private WP_REST_Server $server;

    public function set_up(): void {
        parent::set_up();

        \Escalated\Activator::activate();

        // Create an admin user.
        $this->admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );

        // Create an API token.
        $this->token = wp_generate_password( 64, false );
        global $wpdb;
        $token_table = \Escalated\Escalated::table( 'api_tokens' );
        $wpdb->insert( $token_table, [
            'user_id'    => $this->admin_id,
            'name'       => 'Test Token',
            'token'      => $this->token,
            'abilities'  => wp_json_encode( [ '*' ] ),
            'created_at' => current_time( 'mysql' ),
        ] );

        // Initialize the REST server.
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    /**
     * Helper: Create an authenticated REST request.
     */
    private function create_request( string $method, string $route, array $params = [] ): WP_REST_Request {
        $request = new WP_REST_Request( $method, '/escalated/v1/' . $route );
        $request->set_header( 'Authorization', 'Bearer ' . $this->token );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return $request;
    }

    /**
     * Helper: Create an unauthenticated REST request.
     */
    private function create_unauthenticated_request( string $method, string $route ): WP_REST_Request {
        return new WP_REST_Request( $method, '/escalated/v1/' . $route );
    }

    /**
     * Helper: Create a ticket directly in the database.
     */
    private function create_ticket( array $overrides = [] ): object {
        $service = new \Escalated\Services\TicketService();
        return $service->create( $this->admin_id, array_merge( [
            'subject'     => 'API Test Ticket',
            'description' => 'Created for API test.',
            'priority'    => 'medium',
        ], $overrides ) );
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function test_unauthenticated_request_returns_401(): void {
        $request  = $this->create_unauthenticated_request( 'GET', 'tickets' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 401, $response->get_status() );
    }

    public function test_invalid_token_returns_401(): void {
        $request = new WP_REST_Request( 'GET', '/escalated/v1/tickets' );
        $request->set_header( 'Authorization', 'Bearer invalid_token_here' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 401, $response->get_status() );
    }

    public function test_expired_token_returns_401(): void {
        global $wpdb;
        $token_table = \Escalated\Escalated::table( 'api_tokens' );
        $expired_token = wp_generate_password( 64, false );

        $wpdb->insert( $token_table, [
            'user_id'    => $this->admin_id,
            'name'       => 'Expired Token',
            'token'      => $expired_token,
            'abilities'  => wp_json_encode( [ '*' ] ),
            'expires_at' => '2020-01-01 00:00:00',
            'created_at' => current_time( 'mysql' ),
        ] );

        $request = new WP_REST_Request( 'GET', '/escalated/v1/tickets' );
        $request->set_header( 'Authorization', 'Bearer ' . $expired_token );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 401, $response->get_status() );
    }

    public function test_valid_token_authenticates(): void {
        $request  = $this->create_request( 'GET', 'tickets' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );
    }

    // =========================================================================
    // List Tickets
    // =========================================================================

    public function test_list_tickets_returns_items(): void {
        $this->create_ticket();
        $this->create_ticket( [ 'subject' => 'Second ticket' ] );

        $request  = $this->create_request( 'GET', 'tickets' );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'items', $data );
        $this->assertArrayHasKey( 'total', $data );
        $this->assertGreaterThanOrEqual( 2, $data['total'] );
    }

    public function test_list_tickets_with_status_filter(): void {
        $ticket = $this->create_ticket();

        $request  = $this->create_request( 'GET', 'tickets', [ 'status' => 'open' ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 200, $response->get_status() );
        foreach ( $data['items'] as $item ) {
            $this->assertEquals( 'open', $item->status );
        }
    }

    public function test_list_tickets_with_pagination(): void {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->create_ticket( [ 'subject' => "Ticket {$i}" ] );
        }

        $request  = $this->create_request( 'GET', 'tickets', [
            'per_page' => 2,
            'page'     => 1,
        ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 200, $response->get_status() );
        $this->assertCount( 2, $data['items'] );
        $this->assertGreaterThanOrEqual( 5, $data['total'] );
    }

    // =========================================================================
    // Create Ticket
    // =========================================================================

    public function test_create_ticket_via_api(): void {
        $request = $this->create_request( 'POST', 'tickets', [
            'subject'     => 'API-created ticket',
            'description' => 'Created via REST API.',
            'priority'    => 'high',
        ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 201, $response->get_status() );
        $this->assertArrayHasKey( 'ticket', $data );
        $this->assertEquals( 'API-created ticket', $data['ticket']->subject );
    }

    public function test_create_ticket_missing_subject_returns_error(): void {
        $request = $this->create_request( 'POST', 'tickets', [
            'description' => 'No subject provided.',
        ] );
        $response = $this->server->dispatch( $request );

        // Should be 400 or 422 due to missing required param.
        $this->assertGreaterThanOrEqual( 400, $response->get_status() );
    }

    public function test_create_ticket_invalid_priority_returns_error(): void {
        $request = $this->create_request( 'POST', 'tickets', [
            'subject'     => 'Bad priority test',
            'description' => 'Testing invalid priority.',
            'priority'    => 'nonexistent_priority',
        ] );
        $response = $this->server->dispatch( $request );

        $this->assertGreaterThanOrEqual( 400, $response->get_status() );
    }

    // =========================================================================
    // Get Single Ticket
    // =========================================================================

    public function test_get_ticket_by_reference(): void {
        $ticket = $this->create_ticket();

        $request  = $this->create_request( 'GET', 'tickets/' . $ticket->reference );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 200, $response->get_status() );
        $this->assertArrayHasKey( 'ticket', $data );
        $this->assertEquals( $ticket->reference, $data['ticket']->reference );
    }

    public function test_get_nonexistent_ticket_returns_404(): void {
        $request  = $this->create_request( 'GET', 'tickets/ESC-99999' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 404, $response->get_status() );
    }

    // =========================================================================
    // Add Reply
    // =========================================================================

    public function test_add_reply_via_api(): void {
        $ticket = $this->create_ticket();

        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/reply', [
            'body' => 'This is a reply via API.',
        ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 201, $response->get_status() );
        $this->assertArrayHasKey( 'reply', $data );
        $this->assertStringContainsString( 'reply via API', $data['reply']->body );
    }

    public function test_add_reply_without_body_returns_error(): void {
        $ticket = $this->create_ticket();

        $request  = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/reply' );
        $response = $this->server->dispatch( $request );

        $this->assertGreaterThanOrEqual( 400, $response->get_status() );
    }

    // =========================================================================
    // Add Internal Note
    // =========================================================================

    public function test_add_note_via_api(): void {
        $ticket = $this->create_ticket();

        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/note', [
            'body' => 'Internal note via API.',
        ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 201, $response->get_status() );
        $this->assertArrayHasKey( 'note', $data );
        $this->assertEquals( 1, (int) $data['note']->is_internal_note );
    }

    // =========================================================================
    // Change Status
    // =========================================================================

    public function test_change_status_via_api(): void {
        $ticket = $this->create_ticket();

        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/status', [
            'status' => 'in_progress',
        ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( 'in_progress', $data['ticket']->status );
    }

    public function test_change_status_invalid_transition_returns_error(): void {
        $ticket = $this->create_ticket();

        // Close the ticket first.
        $close_request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/status', [
            'status' => 'closed',
        ] );
        $this->server->dispatch( $close_request );

        // Try invalid transition from closed to in_progress.
        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/status', [
            'status' => 'in_progress',
        ] );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 422, $response->get_status() );
    }

    public function test_change_status_invalid_value_returns_error(): void {
        $ticket = $this->create_ticket();

        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/status', [
            'status' => 'nonexistent_status',
        ] );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 422, $response->get_status() );
    }

    // =========================================================================
    // Change Priority
    // =========================================================================

    public function test_change_priority_via_api(): void {
        $ticket = $this->create_ticket();

        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/priority', [
            'priority' => 'urgent',
        ] );
        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertEquals( 200, $response->get_status() );
        $this->assertEquals( 'urgent', $data['ticket']->priority );
    }

    public function test_change_priority_invalid_value_returns_error(): void {
        $ticket = $this->create_ticket();

        $request = $this->create_request( 'POST', 'tickets/' . $ticket->reference . '/priority', [
            'priority' => 'super_duper_urgent',
        ] );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 422, $response->get_status() );
    }

    // =========================================================================
    // Delete Ticket
    // =========================================================================

    public function test_delete_ticket_via_api(): void {
        $ticket = $this->create_ticket();

        $request  = $this->create_request( 'DELETE', 'tickets/' . $ticket->reference );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        // Ticket should be soft-deleted (not found by normal find).
        $found = Ticket::find( (int) $ticket->id );
        $this->assertNull( $found );
    }

    public function test_delete_nonexistent_ticket_returns_404(): void {
        $request  = $this->create_request( 'DELETE', 'tickets/ESC-99999' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 404, $response->get_status() );
    }

    // =========================================================================
    // Token Ability Restrictions
    // =========================================================================

    public function test_restricted_token_cannot_create(): void {
        global $wpdb;
        $token_table = \Escalated\Escalated::table( 'api_tokens' );
        $read_only_token = wp_generate_password( 64, false );

        $wpdb->insert( $token_table, [
            'user_id'    => $this->admin_id,
            'name'       => 'Read Only Token',
            'token'      => $read_only_token,
            'abilities'  => wp_json_encode( [ 'tickets:read' ] ),
            'created_at' => current_time( 'mysql' ),
        ] );

        $request = new WP_REST_Request( 'POST', '/escalated/v1/tickets' );
        $request->set_header( 'Authorization', 'Bearer ' . $read_only_token );
        $request->set_param( 'subject', 'Should fail' );
        $request->set_param( 'description', 'No permission.' );

        $response = $this->server->dispatch( $request );

        // Should fail as the token only has tickets:read, not tickets:create.
        // The controller checks for tickets:create ability.
        $this->assertContains( $response->get_status(), [ 401, 403 ] );
    }
}
