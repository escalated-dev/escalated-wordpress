<?php

/**
 * NotificationService is wired into the ticket lifecycle at boot.
 *
 * Ticket and reply emails go out as the notification settings say, and the
 * global webhook_url receives lifecycle events. Everything here goes through
 * the real services and the hooks the plugin registers in Escalated::boot();
 * nothing registers NotificationService by hand.
 *
 * Outbound HTTP is recorded with pre_http_request, and mail is captured by the
 * WordPress test suite's mock mailer.
 */

use Escalated\Models\Setting;
use Escalated\Services\AssignmentService;
use Escalated\Services\NotificationService;
use Escalated\Services\TicketService;

class Test_Notification_Wiring extends WP_UnitTestCase
{
    private const WEBHOOK_URL = 'https://hooks.example.com/escalated';

    private TicketService $tickets;

    private int $requester_id;

    private int $agent_id;

    /** @var array<int, array{url: string, args: array}> */
    private array $http = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->tickets = new TicketService;
        $this->requester_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->agent_id = $this->factory->user->create(['role' => 'escalated_agent']);

        reset_phpmailer_instance();

        $this->http = [];
        add_filter('pre_http_request', [$this, 'record_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'record_http'], 10);
        reset_phpmailer_instance();

        parent::tear_down();
    }

    public function record_http($preempt, $args, $url)
    {
        $this->http[] = ['url' => $url, 'args' => $args];

        return [
            'headers' => [],
            'body' => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    // =========================================================================
    // Webhook
    // =========================================================================

    public function test_creating_a_ticket_posts_one_signed_webhook(): void
    {
        Setting::set('webhook_url', self::WEBHOOK_URL);
        Setting::set('webhook_secret', 'webhook-secret');

        $ticket = $this->create_ticket();

        $calls = $this->webhook_calls();
        $this->assertCount(1, $calls);

        $args = $calls[0]['args'];
        $body = json_decode($args['body'], true);
        $this->assertSame('ticket.created', $body['event']);
        $this->assertSame((int) $ticket->id, $body['payload']['ticket']['id']);
        $this->assertSame($ticket->reference, $body['payload']['ticket']['reference']);
        $this->assertSame('ticket.created', $args['headers']['X-Escalated-Event']);
        $this->assertSame(hash_hmac('sha256', $args['body'], 'webhook-secret'), $args['headers']['X-Escalated-Signature']);

        // wp_safe_remote_post: private, loopback and link-local hosts refused.
        $this->assertTrue($args['reject_unsafe_urls']);
    }

    public function test_no_webhook_is_sent_without_a_webhook_url(): void
    {
        $this->create_ticket();

        $this->assertSame([], $this->http);
    }

    public function test_ticket_lifecycle_posts_each_event_to_the_webhook(): void
    {
        Setting::set('webhook_url', self::WEBHOOK_URL);

        $ticket = $this->create_ticket();
        $this->tickets->reply((int) $ticket->id, $this->agent_id, 'We are looking into it.');
        (new AssignmentService)->assign((int) $ticket->id, $this->agent_id);
        $this->tickets->change_status((int) $ticket->id, 'in_progress', $this->agent_id);

        $events = array_map(
            fn ($call) => json_decode($call['args']['body'], true),
            $this->webhook_calls()
        );

        $this->assertSame(
            ['ticket.created', 'reply.created', 'ticket.assigned', 'ticket.status_changed'],
            array_column($events, 'event')
        );
        $this->assertSame($this->agent_id, $events[2]['payload']['new_agent_id']);
        $this->assertNull($events[2]['payload']['old_agent_id']);
        $this->assertSame('open', $events[3]['payload']['old_status']);
        $this->assertSame('in_progress', $events[3]['payload']['new_status']);
    }

    public function test_webhook_to_a_loopback_address_is_refused(): void
    {
        // No interception here: the request has to be refused before any
        // connection is attempted.
        remove_filter('pre_http_request', [$this, 'record_http'], 10);
        Setting::set('webhook_url', 'http://127.0.0.1/escalated-hook');

        $failure = null;
        add_action('escalated_webhook_failed', function ($event, $payload, $error) use (&$failure) {
            $failure = $error;
        }, 10, 3);

        $sent = (new NotificationService)->send_webhook('ticket.created', ['ticket' => ['id' => 1]]);

        $this->assertFalse($sent);
        $this->assertSame('A valid URL was not provided.', $failure);
    }

    // =========================================================================
    // Email
    // =========================================================================

    public function test_creating_a_ticket_emails_the_site_admin(): void
    {
        $ticket = $this->create_ticket();

        $mail = $this->mail_to(get_option('admin_email'));
        $this->assertNotNull($mail, 'No new-ticket email was sent to the site admin.');
        $this->assertStringContainsString($ticket->reference, $mail->subject);
    }

    public function test_new_ticket_email_is_not_sent_when_the_setting_is_off(): void
    {
        Setting::set('notification_new_ticket', '0');

        $this->create_ticket();

        $this->assertNull($this->mail_to(get_option('admin_email')));
    }

    public function test_agent_reply_emails_the_requester(): void
    {
        $ticket = $this->create_ticket();
        reset_phpmailer_instance();

        $this->tickets->reply((int) $ticket->id, $this->agent_id, 'Here is the fix.');

        $mail = $this->mail_to(get_userdata($this->requester_id)->user_email);
        $this->assertNotNull($mail, 'No reply email was sent to the requester.');
        $this->assertStringContainsString('Here is the fix.', $mail->body);
    }

    public function test_reply_email_is_not_sent_when_the_setting_is_off(): void
    {
        Setting::set('notification_ticket_reply', '0');
        $ticket = $this->create_ticket();
        reset_phpmailer_instance();

        $this->tickets->reply((int) $ticket->id, $this->agent_id, 'Here is the fix.');

        $this->assertNull($this->mail_to(get_userdata($this->requester_id)->user_email));
    }

    public function test_emails_use_the_notification_sender_settings(): void
    {
        Setting::set('notification_from_name', 'Acme Support');
        Setting::set('notification_from_email', 'help@acme.test');

        $this->create_ticket();

        $mail = $this->mail_to(get_option('admin_email'));
        $this->assertNotNull($mail);
        $this->assertStringContainsString('From: Acme Support <help@acme.test>', $mail->header);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function create_ticket(): object
    {
        return $this->tickets->create($this->requester_id, [
            'subject' => 'Printer on fire',
            'description' => 'It is still on fire.',
            'priority' => 'medium',
            'channel' => 'web',
        ]);
    }

    /**
     * @return array<int, array{url: string, args: array}>
     */
    private function webhook_calls(): array
    {
        return array_values(array_filter($this->http, fn ($call) => $call['url'] === self::WEBHOOK_URL));
    }

    /**
     * The first captured email addressed to $address, or null.
     */
    private function mail_to(string $address): ?object
    {
        $mailer = tests_retrieve_phpmailer_instance();

        foreach ($mailer->mock_sent as $sent) {
            foreach ($sent['to'] as $recipient) {
                if (strcasecmp($recipient[0], $address) === 0) {
                    return (object) $sent;
                }
            }
        }

        return null;
    }
}
