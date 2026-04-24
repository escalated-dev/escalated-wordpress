<?php

/**
 * Tests for InboundEmailService::find_ticket_by_email resolution order.
 *
 * Verifies that the Message_Id_Util wire-up resolves canonical
 * Message-IDs out of In-Reply-To / References and verifies signed
 * Reply-To addresses on the recipient.
 */

use Escalated\Mail\Message_Id_Util;
use Escalated\Models\Ticket;
use Escalated\Services\InboundEmailService;
use Escalated\Services\TicketService;

class Test_Inbound_Email_Service extends WP_UnitTestCase
{
    private InboundEmailService $service;

    private TicketService $ticket_service;

    private int $user_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->service = new InboundEmailService;
        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
    }

    public function tear_down(): void
    {
        delete_option('escalated_email_inbound_secret');
        delete_option('escalated_email_domain');
        parent::tear_down();
    }

    private function make_ticket(array $overrides = []): object
    {
        $defaults = [
            'subject' => 'Test',
            'description' => 'Body',
            'priority' => 'medium',
            'channel' => 'web',
        ];

        return $this->ticket_service->create($this->user_id, array_merge($defaults, $overrides));
    }

    private function make_message(array $overrides = []): array
    {
        return array_merge([
            'fromEmail' => 'customer@example.com',
            'fromName' => 'Customer',
            'toEmail' => 'support@example.com',
            'subject' => 'hello',
            'bodyText' => 'body',
        ], $overrides);
    }

    public function test_finds_ticket_by_canonical_in_reply_to(): void
    {
        $ticket = $this->make_ticket();
        $message = $this->make_message([
            'inReplyTo' => "<ticket-{$ticket->id}@support.example.com>",
        ]);

        $found = $this->service->find_ticket_by_email($message);
        $this->assertNotNull($found);
        $this->assertEquals($ticket->id, (int) $found->id);
    }

    public function test_finds_ticket_by_canonical_references_header(): void
    {
        $ticket = $this->make_ticket();
        $message = $this->make_message([
            'references' => "<unrelated@mail.com> <ticket-{$ticket->id}@support.example.com>",
        ]);

        $found = $this->service->find_ticket_by_email($message);
        $this->assertNotNull($found);
        $this->assertEquals($ticket->id, (int) $found->id);
    }

    public function test_finds_ticket_by_signed_reply_to(): void
    {
        update_option('escalated_email_domain', 'support.example.com');
        update_option('escalated_email_inbound_secret', 'test-secret');
        $ticket = $this->make_ticket();

        $to = Message_Id_Util::build_reply_to(
            (int) $ticket->id,
            'test-secret',
            'support.example.com'
        );
        $message = $this->make_message(['toEmail' => $to]);

        $found = $this->service->find_ticket_by_email($message);
        $this->assertNotNull($found);
        $this->assertEquals($ticket->id, (int) $found->id);
    }

    public function test_rejects_forged_reply_to_signature(): void
    {
        update_option('escalated_email_domain', 'support.example.com');
        update_option('escalated_email_inbound_secret', 'real-secret');
        $ticket = $this->make_ticket();

        $forged = Message_Id_Util::build_reply_to(
            (int) $ticket->id,
            'wrong-secret',
            'support.example.com'
        );
        $message = $this->make_message(['toEmail' => $forged]);

        $found = $this->service->find_ticket_by_email($message);
        $this->assertNull($found);
    }

    public function test_ignores_signed_reply_to_when_secret_blank(): void
    {
        delete_option('escalated_email_inbound_secret');
        $ticket = $this->make_ticket();

        $to = Message_Id_Util::build_reply_to(
            (int) $ticket->id,
            'test-secret',
            'support.example.com'
        );
        $message = $this->make_message(['toEmail' => $to]);

        $found = $this->service->find_ticket_by_email($message);
        $this->assertNull($found);
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        $message = $this->make_message(['subject' => 'Completely unrelated']);

        $found = $this->service->find_ticket_by_email($message);
        $this->assertNull($found);
    }
}
