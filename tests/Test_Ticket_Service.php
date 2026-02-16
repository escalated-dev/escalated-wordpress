<?php
/**
 * Tests for the TicketService class.
 *
 * Covers ticket CRUD operations, status transitions, replies, internal notes,
 * priority changes, tags, and department changes.
 *
 * @package Escalated
 */

use Escalated\Services\TicketService;
use Escalated\Models\Ticket;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\TicketActivity;
use Escalated\Helpers\Enums;

class Test_Ticket_Service extends WP_UnitTestCase {

    /**
     * @var TicketService
     */
    private TicketService $service;

    /**
     * @var int
     */
    private int $user_id;

    /**
     * @var int
     */
    private int $agent_id;

    public function set_up(): void {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->service  = new TicketService();
        $this->user_id  = $this->factory->user->create( [ 'role' => 'subscriber' ] );
        $this->agent_id = $this->factory->user->create( [ 'role' => 'escalated_agent' ] );
    }

    /**
     * Helper: Create a ticket via the service.
     */
    private function create_ticket( array $overrides = [] ): object {
        $defaults = [
            'subject'     => 'Test ticket subject',
            'description' => 'Test ticket description body.',
            'priority'    => 'medium',
            'channel'     => 'web',
        ];

        return $this->service->create( $this->user_id, array_merge( $defaults, $overrides ) );
    }

    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function test_create_ticket(): void {
        $ticket = $this->create_ticket();

        $this->assertIsObject( $ticket );
        $this->assertNotEmpty( $ticket->id );
        $this->assertNotEmpty( $ticket->reference );
        $this->assertEquals( 'open', $ticket->status );
        $this->assertEquals( 'medium', $ticket->priority );
        $this->assertEquals( $this->user_id, (int) $ticket->requester_id );
        $this->assertEquals( 'Test ticket subject', $ticket->subject );
    }

    public function test_create_ticket_generates_unique_references(): void {
        $ticket1 = $this->create_ticket( [ 'subject' => 'First' ] );
        $ticket2 = $this->create_ticket( [ 'subject' => 'Second' ] );

        $this->assertNotEquals( $ticket1->reference, $ticket2->reference );
    }

    public function test_create_ticket_uses_default_priority(): void {
        $ticket = $this->service->create( $this->user_id, [
            'subject'     => 'No explicit priority',
            'description' => 'Should use default.',
        ] );

        $this->assertEquals( 'medium', $ticket->priority );
    }

    public function test_create_ticket_with_custom_priority(): void {
        $ticket = $this->create_ticket( [ 'priority' => 'critical' ] );

        $this->assertEquals( 'critical', $ticket->priority );
    }

    public function test_create_ticket_with_department(): void {
        global $wpdb;
        $dept_table = \Escalated\Escalated::table( 'departments' );
        $wpdb->insert( $dept_table, [
            'name'       => 'Engineering',
            'slug'       => 'engineering',
            'is_active'  => 1,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );
        $dept_id = $wpdb->insert_id;

        $ticket = $this->create_ticket( [ 'department_id' => $dept_id ] );

        $this->assertEquals( $dept_id, (int) $ticket->department_id );
    }

    public function test_create_ticket_with_tags(): void {
        global $wpdb;
        $tag_table = \Escalated\Escalated::table( 'tags' );

        $wpdb->insert( $tag_table, [
            'name'       => 'Bug',
            'slug'       => 'bug',
            'color'      => '#EF4444',
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );
        $tag_id = $wpdb->insert_id;

        $ticket = $this->create_ticket( [ 'tags' => [ $tag_id ] ] );

        $tags = Tag::for_ticket( $ticket->id );
        $this->assertCount( 1, $tags );
        $this->assertEquals( 'Bug', $tags[0]->name );
    }

    public function test_create_ticket_logs_activity(): void {
        global $wpdb;
        $ticket = $this->create_ticket();

        $activity_table = \Escalated\Escalated::table( 'ticket_activities' );
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$activity_table} WHERE ticket_id = %d AND type = 'status_changed' ORDER BY id DESC LIMIT 1",
                $ticket->id
            )
        );

        $this->assertNotNull( $activity );
        $properties = json_decode( $activity->properties, true );
        $this->assertEquals( 'open', $properties['new_status'] );
    }

    public function test_create_ticket_fires_action(): void {
        $fired = false;
        add_action( 'escalated_ticket_created', function () use ( &$fired ) {
            $fired = true;
        } );

        $this->create_ticket();

        $this->assertTrue( $fired, 'escalated_ticket_created action should fire.' );
    }

    // =========================================================================
    // Guest Ticket Tests
    // =========================================================================

    public function test_create_guest_ticket(): void {
        $ticket = $this->service->create_guest( [
            'subject'     => 'Guest issue',
            'description' => 'Guest description.',
            'guest_name'  => 'Jane Doe',
            'guest_email' => 'jane@example.com',
        ] );

        $this->assertIsObject( $ticket );
        $this->assertNull( $ticket->requester_id );
        $this->assertEquals( 'Jane Doe', $ticket->guest_name );
        $this->assertEquals( 'jane@example.com', $ticket->guest_email );
        $this->assertNotEmpty( $ticket->guest_token );
        $this->assertEquals( 64, strlen( $ticket->guest_token ) );
    }

    public function test_guest_ticket_findable_by_token(): void {
        $ticket = $this->service->create_guest( [
            'subject'     => 'Token lookup',
            'description' => 'Test.',
            'guest_name'  => 'Test Guest',
            'guest_email' => 'test@example.com',
        ] );

        $found = Ticket::find_by_guest_token( $ticket->guest_token );
        $this->assertNotNull( $found );
        $this->assertEquals( $ticket->id, $found->id );
    }

    // =========================================================================
    // Update Tests
    // =========================================================================

    public function test_update_ticket_subject(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->update_ticket( (int) $ticket->id, [
            'subject' => 'Updated subject',
        ] );

        $this->assertEquals( 'Updated subject', $updated->subject );
    }

    public function test_update_ticket_description(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->update_ticket( (int) $ticket->id, [
            'description' => '<p>Updated description.</p>',
        ] );

        $this->assertStringContainsString( 'Updated description', $updated->description );
    }

    public function test_update_ticket_ignores_disallowed_fields(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->update_ticket( (int) $ticket->id, [
            'status'   => 'closed',  // Not allowed via update_ticket.
            'priority' => 'critical', // Not allowed via update_ticket.
        ] );

        $this->assertEquals( 'open', $updated->status );
        $this->assertEquals( 'medium', $updated->priority );
    }

    // =========================================================================
    // Status Transition Tests
    // =========================================================================

    public function test_change_status_to_in_progress(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->change_status( (int) $ticket->id, 'in_progress', $this->agent_id );

        $this->assertEquals( 'in_progress', $updated->status );
    }

    public function test_change_status_to_resolved_sets_resolved_at(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->change_status( (int) $ticket->id, 'resolved', $this->agent_id );

        $this->assertEquals( 'resolved', $updated->status );
        $this->assertNotNull( $updated->resolved_at );
    }

    public function test_change_status_to_closed_sets_closed_at(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->change_status( (int) $ticket->id, 'closed', $this->agent_id );

        $this->assertEquals( 'closed', $updated->status );
        $this->assertNotNull( $updated->closed_at );
    }

    public function test_reopen_clears_timestamps(): void {
        $ticket = $this->create_ticket();
        $this->service->change_status( (int) $ticket->id, 'resolved', $this->agent_id );
        $reopened = $this->service->change_status( (int) $ticket->id, 'reopened', $this->agent_id );

        $this->assertEquals( 'reopened', $reopened->status );
        $this->assertNull( $reopened->resolved_at );
        $this->assertNull( $reopened->closed_at );
    }

    public function test_invalid_transition_throws_exception(): void {
        $ticket = $this->create_ticket();
        $this->service->change_status( (int) $ticket->id, 'closed', $this->agent_id );

        $this->expectException( \InvalidArgumentException::class );
        // Cannot go from closed to in_progress.
        $this->service->change_status( (int) $ticket->id, 'in_progress', $this->agent_id );
    }

    public function test_change_status_nonexistent_ticket_throws(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->service->change_status( 999999, 'resolved' );
    }

    public function test_change_status_fires_status_changed_action(): void {
        $old = null;
        $new = null;
        add_action( 'escalated_ticket_status_changed', function ( $t, $o, $n ) use ( &$old, &$new ) {
            $old = $o;
            $new = $n;
        }, 10, 3 );

        $ticket = $this->create_ticket();
        $this->service->change_status( (int) $ticket->id, 'in_progress', $this->agent_id );

        $this->assertEquals( 'open', $old );
        $this->assertEquals( 'in_progress', $new );
    }

    public function test_resolve_shorthand(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->resolve( (int) $ticket->id, $this->agent_id );

        $this->assertEquals( 'resolved', $updated->status );
    }

    public function test_close_shorthand(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->close( (int) $ticket->id, $this->agent_id );

        $this->assertEquals( 'closed', $updated->status );
    }

    public function test_reopen_shorthand(): void {
        $ticket = $this->create_ticket();
        $this->service->resolve( (int) $ticket->id, $this->agent_id );
        $updated = $this->service->reopen( (int) $ticket->id, $this->agent_id );

        $this->assertEquals( 'reopened', $updated->status );
    }

    // =========================================================================
    // Reply Tests
    // =========================================================================

    public function test_reply_creates_reply(): void {
        $ticket = $this->create_ticket();
        $reply  = $this->service->reply( (int) $ticket->id, $this->agent_id, 'This is a reply.' );

        $this->assertIsObject( $reply );
        $this->assertEquals( (int) $ticket->id, (int) $reply->ticket_id );
        $this->assertEquals( $this->agent_id, (int) $reply->author_id );
        $this->assertStringContainsString( 'This is a reply', $reply->body );
        $this->assertEquals( 0, (int) $reply->is_internal_note );
    }

    public function test_reply_sets_first_response_at(): void {
        $ticket = $this->create_ticket();

        // Agent (non-requester) reply should set first_response_at.
        $this->service->reply( (int) $ticket->id, $this->agent_id, 'First response.' );

        $updated = Ticket::find( (int) $ticket->id );
        $this->assertNotNull( $updated->first_response_at );
    }

    public function test_requester_reply_does_not_set_first_response(): void {
        $ticket = $this->create_ticket();

        // Requester replying to their own ticket should NOT set first_response_at.
        $this->service->reply( (int) $ticket->id, $this->user_id, 'Additional info.' );

        $updated = Ticket::find( (int) $ticket->id );
        $this->assertEmpty( $updated->first_response_at );
    }

    public function test_multiple_replies_returned_for_ticket(): void {
        $ticket = $this->create_ticket();
        $this->service->reply( (int) $ticket->id, $this->agent_id, 'Reply 1.' );
        $this->service->reply( (int) $ticket->id, $this->user_id, 'Reply 2.' );
        $this->service->reply( (int) $ticket->id, $this->agent_id, 'Reply 3.' );

        $replies = Reply::for_ticket( (int) $ticket->id );
        $this->assertCount( 3, $replies );
    }

    // =========================================================================
    // Internal Note Tests
    // =========================================================================

    public function test_add_note(): void {
        $ticket = $this->create_ticket();
        $note   = $this->service->add_note( (int) $ticket->id, $this->agent_id, 'Internal note content.' );

        $this->assertIsObject( $note );
        $this->assertEquals( 1, (int) $note->is_internal_note );
        $this->assertEquals( 'note', $note->type );
        $this->assertStringContainsString( 'Internal note content', $note->body );
    }

    public function test_note_logs_activity(): void {
        global $wpdb;
        $ticket = $this->create_ticket();
        $this->service->add_note( (int) $ticket->id, $this->agent_id, 'A note.' );

        $activity_table = \Escalated\Escalated::table( 'ticket_activities' );
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$activity_table} WHERE ticket_id = %d AND type = 'note_added' LIMIT 1",
                $ticket->id
            )
        );

        $this->assertNotNull( $activity );
    }

    // =========================================================================
    // Priority Tests
    // =========================================================================

    public function test_change_priority(): void {
        $ticket = $this->create_ticket();
        $updated = $this->service->change_priority( (int) $ticket->id, 'critical', $this->agent_id );

        $this->assertEquals( 'critical', $updated->priority );
    }

    public function test_change_priority_logs_activity(): void {
        global $wpdb;
        $ticket = $this->create_ticket();
        $this->service->change_priority( (int) $ticket->id, 'high', $this->agent_id );

        $activity_table = \Escalated\Escalated::table( 'ticket_activities' );
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$activity_table} WHERE ticket_id = %d AND type = 'priority_changed' LIMIT 1",
                $ticket->id
            )
        );

        $this->assertNotNull( $activity );
        $props = json_decode( $activity->properties, true );
        $this->assertEquals( 'medium', $props['old_priority'] );
        $this->assertEquals( 'high', $props['new_priority'] );
    }

    // =========================================================================
    // Tag Tests
    // =========================================================================

    public function test_add_and_remove_tags(): void {
        global $wpdb;
        $tag_table = \Escalated\Escalated::table( 'tags' );

        $wpdb->insert( $tag_table, [
            'name' => 'Feature', 'slug' => 'feature', 'color' => '#3B82F6',
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ] );
        $tag_id = $wpdb->insert_id;

        $ticket = $this->create_ticket();
        $this->service->add_tags( (int) $ticket->id, [ $tag_id ], $this->agent_id );

        $tags = Tag::for_ticket( (int) $ticket->id );
        $this->assertCount( 1, $tags );

        $this->service->remove_tags( (int) $ticket->id, [ $tag_id ], $this->agent_id );

        $tags = Tag::for_ticket( (int) $ticket->id );
        $this->assertCount( 0, $tags );
    }

    // =========================================================================
    // Department Change Tests
    // =========================================================================

    public function test_change_department(): void {
        global $wpdb;
        $dept_table = \Escalated\Escalated::table( 'departments' );

        $wpdb->insert( $dept_table, [
            'name' => 'Sales', 'slug' => 'sales', 'is_active' => 1,
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ] );
        $sales_id = $wpdb->insert_id;

        $wpdb->insert( $dept_table, [
            'name' => 'Support', 'slug' => 'support', 'is_active' => 1,
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ] );
        $support_id = $wpdb->insert_id;

        $ticket = $this->create_ticket( [ 'department_id' => $sales_id ] );
        $updated = $this->service->change_department( (int) $ticket->id, $support_id, $this->agent_id );

        $this->assertEquals( $support_id, (int) $updated->department_id );
    }
}
