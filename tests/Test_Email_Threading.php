<?php

/**
 * Tests for Email Threading and Branded Email Templates.
 *
 * Covers Message-ID generation, In-Reply-To/References headers,
 * branded template rendering, and branding settings.
 */

use Escalated\Mail\Branded_Email_Template;
use Escalated\Mail\Email_Threading;
use Escalated\Models\Setting;
use Escalated\Services\TicketService;

class Test_Email_Threading extends WP_UnitTestCase
{
    private TicketService $ticket_service;

    private int $user_id;

    private int $agent_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->ticket_service = new TicketService;
        $this->user_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);

        Email_Threading::reset_context();
    }

    public function tear_down(): void
    {
        Email_Threading::reset_context();
        parent::tear_down();
    }

    /**
     * Helper: Create a ticket via the service.
     */
    private function create_ticket(array $overrides = []): object
    {
        $defaults = [
            'subject' => 'Test ticket',
            'description' => 'Test description.',
            'priority' => 'medium',
            'channel' => 'web',
        ];

        return $this->ticket_service->create($this->user_id, array_merge($defaults, $overrides));
    }

    // =========================================================================
    // Message-ID Generation Tests
    // =========================================================================

    // Message-ID format matches the canonical Message_Id_Util shape:
    //   anchor:  <ticket-{id}@{domain}>
    //   reply:   <ticket-{id}-reply-{replyId}@{domain}>
    // Reference strings are no longer embedded — inbound routing uses
    // the ticket id from the Message-ID or the signed Reply-To.

    public function test_generate_ticket_message_id(): void
    {
        $ticket = $this->create_ticket();

        $message_id = Email_Threading::generate_ticket_message_id($ticket, 'example.com');

        $this->assertEquals("<ticket-{$ticket->id}@example.com>", $message_id);
    }

    public function test_generate_reply_message_id(): void
    {
        $ticket = $this->create_ticket();
        $reply = $this->ticket_service->reply((int) $ticket->id, $this->agent_id, 'A reply.');

        $message_id = Email_Threading::generate_message_id($ticket, $reply, 'example.com');

        $this->assertEquals("<ticket-{$ticket->id}-reply-{$reply->id}@example.com>", $message_id);
    }

    public function test_generate_message_id_without_reply(): void
    {
        $ticket = $this->create_ticket();

        $message_id = Email_Threading::generate_message_id($ticket, null, 'example.com');

        $this->assertStringContainsString('ticket-', $message_id);
        $this->assertStringNotContainsString('reply-', $message_id);
    }

    // =========================================================================
    // Signed Reply-To Tests
    // =========================================================================

    public function test_signed_reply_to_returns_null_when_secret_blank(): void
    {
        delete_option('escalated_email_inbound_secret');
        $ticket = $this->create_ticket();

        $this->assertNull(Email_Threading::get_signed_reply_to($ticket, 'example.com'));
    }

    public function test_signed_reply_to_returns_signed_address_when_configured(): void
    {
        update_option('escalated_email_inbound_secret', 'test-secret-for-hmac');
        $ticket = $this->create_ticket();

        $reply_to = Email_Threading::get_signed_reply_to($ticket, 'example.com');
        $this->assertNotNull($reply_to);
        $this->assertMatchesRegularExpression(
            "/^reply\\+{$ticket->id}\\.[a-f0-9]{8}@example\\.com$/",
            $reply_to
        );

        delete_option('escalated_email_inbound_secret');
    }

    public function test_threading_headers_include_reply_to_when_secret_configured(): void
    {
        update_option('escalated_email_domain', 'support.test');
        update_option('escalated_email_inbound_secret', 'hmac-key');
        $threading = new Email_Threading;
        $ticket = $this->create_ticket();

        $threading->set_ticket_context($ticket);
        $args = $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $headers_str = implode("\n", $args['headers']);
        $this->assertStringContainsString('Reply-To: reply+', $headers_str);
        $this->assertStringContainsString('@support.test', $headers_str);

        delete_option('escalated_email_domain');
        delete_option('escalated_email_inbound_secret');
    }

    public function test_threading_headers_omit_reply_to_when_secret_blank(): void
    {
        delete_option('escalated_email_inbound_secret');
        $threading = new Email_Threading;
        $ticket = $this->create_ticket();

        $threading->set_ticket_context($ticket);
        $args = $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $headers_str = implode("\n", $args['headers']);
        $this->assertStringNotContainsString('Reply-To:', $headers_str);
    }

    // =========================================================================
    // Header Injection Tests
    // =========================================================================

    public function test_add_threading_headers_for_ticket(): void
    {
        $threading = new Email_Threading;
        $ticket = $this->create_ticket();

        $threading->set_ticket_context($ticket);

        $args = $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $this->assertIsArray($args['headers']);

        $headers_str = implode("\n", $args['headers']);
        $this->assertStringContainsString('Message-ID:', $headers_str);
        $this->assertStringContainsString("ticket-{$ticket->id}@", $headers_str);
    }

    public function test_add_threading_headers_for_reply(): void
    {
        $threading = new Email_Threading;
        $ticket = $this->create_ticket();
        $reply = $this->ticket_service->reply((int) $ticket->id, $this->agent_id, 'A reply.');

        $threading->set_reply_context($reply, $ticket);

        $args = $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $headers_str = implode("\n", $args['headers']);
        $this->assertStringContainsString('Message-ID:', $headers_str);
        $this->assertStringContainsString('In-Reply-To:', $headers_str);
        $this->assertStringContainsString('References:', $headers_str);
    }

    public function test_no_headers_without_context(): void
    {
        $threading = new Email_Threading;

        $args = $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $this->assertEmpty($args['headers']);
    }

    public function test_context_resets_after_use(): void
    {
        $threading = new Email_Threading;
        $ticket = $this->create_ticket();

        $threading->set_ticket_context($ticket);
        $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $this->assertNull(Email_Threading::get_current_ticket());
    }

    public function test_string_headers_are_preserved(): void
    {
        $threading = new Email_Threading;
        $ticket = $this->create_ticket();

        $threading->set_ticket_context($ticket);

        $args = $threading->add_threading_headers([
            'to' => 'user@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => "From: admin@example.com\nX-Custom: value",
        ]);

        $headers_str = implode("\n", $args['headers']);
        $this->assertStringContainsString('From: admin@example.com', $headers_str);
        $this->assertStringContainsString('Message-ID:', $headers_str);
    }

    // =========================================================================
    // Branded Email Template Tests
    // =========================================================================

    public function test_render_branded_template(): void
    {
        $html = Branded_Email_Template::render(
            'Test Subject',
            '<p>Hello world</p>'
        );

        $this->assertStringContainsString('Test Subject', $html);
        $this->assertStringContainsString('Hello world', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Powered by Escalated', $html);
    }

    public function test_render_with_logo(): void
    {
        $html = Branded_Email_Template::render(
            'Test',
            '<p>Body</p>',
            ['logo_url' => 'https://example.com/logo.png']
        );

        $this->assertStringContainsString('https://example.com/logo.png', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_render_with_accent_color(): void
    {
        $html = Branded_Email_Template::render(
            'Test',
            '<p>Body</p>',
            ['accent_color' => '#EF4444']
        );

        $this->assertStringContainsString('#EF4444', $html);
    }

    public function test_render_with_footer_text(): void
    {
        $html = Branded_Email_Template::render(
            'Test',
            '<p>Body</p>',
            ['footer_text' => 'Custom Footer Text']
        );

        $this->assertStringContainsString('Custom Footer Text', $html);
    }

    public function test_render_with_company_name(): void
    {
        $html = Branded_Email_Template::render(
            'Test',
            '<p>Body</p>',
            ['company_name' => 'Acme Corp']
        );

        $this->assertStringContainsString('Acme Corp', $html);
    }

    public function test_invalid_accent_color_uses_default(): void
    {
        $html = Branded_Email_Template::render(
            'Test',
            '<p>Body</p>',
            ['accent_color' => 'not-a-color']
        );

        $this->assertStringContainsString('#3B82F6', $html);
    }

    // =========================================================================
    // Branding Settings Tests
    // =========================================================================

    public function test_get_settings_returns_defaults(): void
    {
        $settings = Branded_Email_Template::get_settings();

        $this->assertArrayHasKey('logo_url', $settings);
        $this->assertArrayHasKey('accent_color', $settings);
        $this->assertArrayHasKey('footer_text', $settings);
        $this->assertArrayHasKey('company_name', $settings);
    }

    public function test_update_settings(): void
    {
        Branded_Email_Template::update_settings([
            'email_logo_url' => 'https://example.com/brand.png',
            'email_accent_color' => '#10B981',
            'email_footer_text' => 'My Footer',
            'email_company_name' => 'My Company',
        ]);

        $this->assertEquals('https://example.com/brand.png', Setting::get('email_logo_url'));
        $this->assertEquals('#10B981', Setting::get('email_accent_color'));
        $this->assertEquals('My Footer', Setting::get('email_footer_text'));
        $this->assertEquals('My Company', Setting::get('email_company_name'));
    }

    public function test_render_uses_stored_settings(): void
    {
        Setting::set('email_accent_color', '#F59E0B');
        Setting::set('email_footer_text', 'Stored footer');

        $html = Branded_Email_Template::render('Test', '<p>Body</p>');

        $this->assertStringContainsString('#F59E0B', $html);
        $this->assertStringContainsString('Stored footer', $html);
    }
}
