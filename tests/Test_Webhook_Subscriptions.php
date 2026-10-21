<?php

/**
 * Outbound webhook subscriptions: several webhooks, per-webhook events and
 * secrets, a delivery log, retries with backoff, sending from WP-Cron, the
 * URL guard on save and on send, and the move from the single webhook_url
 * setting.
 *
 * Events are fired through the real services and the hooks registered at
 * boot. DNS answers come from the escalated_outbound_url_resolve filter and
 * HTTP is answered by pre_http_request.
 */

use Escalated\Admin\Admin_Webhooks;
use Escalated\Models\AuditLog;
use Escalated\Models\Setting;
use Escalated\Models\Tag;
use Escalated\Models\Webhook;
use Escalated\Models\WebhookDelivery;
use Escalated\Services\EventVocabulary;
use Escalated\Services\TicketService;
use Escalated\Services\WebhookDispatcher;

class Test_Webhook_Subscriptions extends WP_UnitTestCase
{
    private TicketService $tickets;

    private int $requester_id;

    private int $admin_id;

    /** @var array<int, array{url: string, args: array}> */
    private array $http = [];

    /** @var array<string, int> url => HTTP status to answer */
    private array $status = [];

    /** @var array<string, array> host => addresses */
    private array $dns = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->tickets = new TicketService;
        $this->requester_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('escalated_webhook_manage');

        $this->http = [];
        $this->status = [];
        $this->dns = [
            'hooks.example.com' => ['93.184.216.34'],
            'other.example.com' => ['93.184.216.35'],
            'internal.example.com' => ['10.0.0.5'],
            'mixed.example.com' => ['93.184.216.36', '127.0.0.1'],
        ];
        add_filter('pre_http_request', [$this, 'answer_http'], 10, 3);
        add_filter('escalated_outbound_url_resolve', [$this, 'answer_dns'], 10, 2);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'answer_http'], 10);
        remove_filter('escalated_outbound_url_resolve', [$this, 'answer_dns'], 10);
        wp_set_current_user(0);

        parent::tear_down();
    }

    public function answer_http($preempt, $args, $url)
    {
        $this->http[] = ['url' => $url, 'args' => $args];
        $code = $this->status[$url] ?? 200;

        return [
            'headers' => [],
            'body' => $code === 200 ? 'ok' : 'nope',
            'response' => ['code' => $code, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }

    public function answer_dns($addresses, $host)
    {
        return $this->dns[$host] ?? [];
    }

    // =========================================================================
    // Subscriptions
    // =========================================================================

    public function test_each_webhook_gets_only_the_events_it_subscribes_to_signed_with_its_own_secret(): void
    {
        $all = $this->webhook('https://hooks.example.com/all', EventVocabulary::WEBHOOK_EVENTS, 'secret-a');
        $replies = $this->webhook('https://other.example.com/replies', ['reply.created'], 'secret-b');

        $ticket = $this->make_ticket();
        $this->tickets->reply((int) $ticket->id, $this->admin_id, 'On it.');
        (new WebhookDispatcher)->process_due();

        $to_all = $this->calls('https://hooks.example.com/all');
        $to_replies = $this->calls('https://other.example.com/replies');

        $all_events = array_map(fn ($c) => $c['body']['event'], $to_all);
        $this->assertContains('ticket.created', $all_events);
        $this->assertContains('reply.created', $all_events);
        $this->assertSame(['reply.created'], array_map(fn ($c) => $c['body']['event'], $to_replies));

        $call = $to_replies[0];
        $this->assertSame(hash_hmac('sha256', $call['args']['body'], 'secret-b'), $call['args']['headers']['X-Escalated-Signature']);
        $this->assertSame('reply.created', $call['args']['headers']['X-Escalated-Event']);
        $this->assertSame((int) $ticket->id, $call['body']['payload']['ticket']['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $call['body']['occurred_at']);
        $this->assertSame(0, $call['args']['redirection']);

        $this->assertSame(count($to_all), WebhookDelivery::count_for_webhook($all));
        $this->assertSame('delivered', WebhookDelivery::latest_for_webhook($replies)->status);
    }

    public function test_events_are_queued_and_sent_from_cron_not_in_the_request(): void
    {
        $id = $this->webhook('https://hooks.example.com/all', ['ticket.created']);
        wp_clear_scheduled_hook(WebhookDispatcher::KICK_HOOK);

        $this->make_ticket();

        $this->assertSame([], $this->http, 'The webhook was sent inside the request.');
        $this->assertSame('pending', WebhookDelivery::latest_for_webhook($id)->status);
        $this->assertNotFalse(wp_next_scheduled(WebhookDispatcher::KICK_HOOK));
        $this->assertNotFalse(wp_next_scheduled(WebhookDispatcher::CRON_HOOK));

        do_action(WebhookDispatcher::CRON_HOOK);

        $this->assertCount(1, $this->calls('https://hooks.example.com/all'));
        $this->assertSame('delivered', WebhookDelivery::latest_for_webhook($id)->status);
    }

    public function test_an_inactive_webhook_gets_nothing(): void
    {
        $id = $this->webhook('https://hooks.example.com/all', ['ticket.created'], '', 0);

        $this->make_ticket();
        (new WebhookDispatcher)->process_due();

        $this->assertSame([], $this->http);
        $this->assertSame(0, WebhookDelivery::count_for_webhook($id));
    }

    public function test_a_subscription_stored_under_legacy_names_still_receives_the_events(): void
    {
        global $wpdb;
        $id = $this->webhook('https://hooks.example.com/all', ['ticket.created']);
        $wpdb->update(Webhook::table(), ['events' => wp_json_encode(['ticket.tagged', 'internal_note.added'])], ['id' => $id]);

        $ticket_id = (int) $this->make_ticket()->id;
        $this->tickets->add_tags($ticket_id, [$this->make_tag()]);
        $this->tickets->add_note($ticket_id, $this->admin_id, 'Internal.');
        (new WebhookDispatcher)->process_due();

        $events = array_map(fn ($c) => $c['body']['event'], $this->calls('https://hooks.example.com/all'));
        $this->assertSame(['ticket.tag_added', 'note.created'], $events);
        $this->assertSame(['ticket.tag_added', 'note.created'], Webhook::subscribed_events(Webhook::find($id)));
    }

    // =========================================================================
    // Retries
    // =========================================================================

    public function test_a_failed_delivery_is_retried_with_backoff_then_given_up(): void
    {
        $id = $this->webhook('https://hooks.example.com/flaky', ['ticket.created']);
        $this->status['https://hooks.example.com/flaky'] = 500;

        $this->make_ticket();
        $dispatcher = new WebhookDispatcher;
        $dispatcher->process_due();

        $delivery = WebhookDelivery::latest_for_webhook($id);
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertSame(500, (int) $delivery->response_code);
        $this->assertEqualsWithDelta(time() + 120, strtotime($delivery->next_attempt_at.' UTC'), 5);

        // Not due yet.
        $this->assertSame(0, $dispatcher->process_due());

        $this->make_due($delivery->id);
        $dispatcher->process_due();
        $delivery = WebhookDelivery::find($delivery->id);
        $this->assertSame(2, (int) $delivery->attempts);
        $this->assertEqualsWithDelta(time() + 240, strtotime($delivery->next_attempt_at.' UTC'), 5);

        $this->make_due($delivery->id);
        $dispatcher->process_due();
        $delivery = WebhookDelivery::find($delivery->id);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(3, (int) $delivery->attempts);

        $this->make_due($delivery->id);
        $this->assertSame(0, $dispatcher->process_due());
        $this->assertCount(3, $this->calls('https://hooks.example.com/flaky'));
    }

    public function test_a_retry_that_succeeds_is_marked_delivered(): void
    {
        $id = $this->webhook('https://hooks.example.com/flaky', ['ticket.created']);
        $this->status['https://hooks.example.com/flaky'] = 503;
        $this->make_ticket();
        (new WebhookDispatcher)->process_due();

        $this->status['https://hooks.example.com/flaky'] = 200;
        $delivery = WebhookDelivery::latest_for_webhook($id);
        $this->make_due($delivery->id);
        (new WebhookDispatcher)->process_due();

        $delivery = WebhookDelivery::find($delivery->id);
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(2, (int) $delivery->attempts);
        $this->assertNotNull($delivery->delivered_at);
    }

    // =========================================================================
    // URL guard on send
    // =========================================================================

    /**
     * @dataProvider refused_urls
     */
    public function test_a_stored_url_that_is_not_public_is_never_requested(string $url): void
    {
        global $wpdb;
        // Written straight to the table, as a row from before the check or a
        // host re-pointed after it passed would be.
        $wpdb->insert(Webhook::table(), [
            'url' => $url,
            'events' => wp_json_encode(['ticket.created']),
            'active' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $id = (int) $wpdb->insert_id;

        $this->make_ticket();
        (new WebhookDispatcher)->process_due();

        $this->assertSame([], $this->http);
        $delivery = WebhookDelivery::latest_for_webhook($id);
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('public address', $delivery->response_body);
    }

    public static function refused_urls(): array
    {
        return [
            'private dns' => ['https://internal.example.com/hook'],
            'one private address among public ones' => ['https://mixed.example.com/hook'],
            'loopback literal' => ['http://127.0.0.1/hook'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/hook'],
            'does not resolve' => ['https://nowhere.example.com/hook'],
            'not http' => ['ftp://hooks.example.com/hook'],
        ];
    }

    // =========================================================================
    // The old single webhook_url setting
    // =========================================================================

    public function test_the_old_webhook_url_setting_becomes_a_subscription_to_every_event_on_upgrade(): void
    {
        Setting::set('webhook_url', 'https://hooks.example.com/legacy');
        Setting::set('webhook_secret', 'legacy-secret');
        update_option('escalated_schema_version', 'widget-embed-1');

        \Escalated\Activator::maybe_upgrade();

        $hooks = Webhook::all();
        $this->assertCount(1, $hooks);
        $this->assertSame('https://hooks.example.com/legacy', $hooks[0]->url);
        $this->assertSame('legacy-secret', $hooks[0]->secret);
        $this->assertSame(1, (int) $hooks[0]->active);
        $this->assertEqualsCanonicalizing(EventVocabulary::WEBHOOK_EVENTS, Webhook::subscribed_events($hooks[0]));
        $this->assertSame('', (string) Setting::get('webhook_url'));
        $this->assertSame('', (string) Setting::get('webhook_secret'));

        // Running the upgrade again does not duplicate it.
        update_option('escalated_schema_version', 'widget-embed-1');
        \Escalated\Activator::maybe_upgrade();
        $this->assertCount(1, Webhook::all());
    }

    public function test_a_webhook_url_set_in_code_keeps_receiving_events(): void
    {
        Setting::set('webhook_url', 'https://hooks.example.com/legacy');
        Setting::set('webhook_secret', 'legacy-secret');

        $this->make_ticket();
        (new WebhookDispatcher)->process_due();

        $calls = $this->calls('https://hooks.example.com/legacy');
        $this->assertCount(1, $calls);
        $this->assertSame(hash_hmac('sha256', $calls[0]['args']['body'], 'legacy-secret'), $calls[0]['args']['headers']['X-Escalated-Signature']);
    }

    // =========================================================================
    // Admin screen
    // =========================================================================

    public function test_admin_can_create_a_webhook_and_it_is_audited_without_the_secret(): void
    {
        wp_set_current_user($this->admin_id);

        $redirect = (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'create',
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_create'),
            'url' => 'https://hooks.example.com/new',
            'secret' => 'shh-its-secret',
            'events' => ['ticket.created', 'ticket.tagged', 'not.an.event'],
            'active' => '1',
        ]);

        $this->assertStringContainsString('message=created', $redirect);
        $hooks = Webhook::all();
        $this->assertCount(1, $hooks);
        $this->assertSame('https://hooks.example.com/new', $hooks[0]->url);
        $this->assertSame('shh-its-secret', $hooks[0]->secret);
        $this->assertSame(['ticket.created', 'ticket.tag_added'], Webhook::subscribed_events($hooks[0]));

        $audit = AuditLog::all(['action' => 'webhook.created'])[0] ?? null;
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('shh-its-secret', (string) $audit->new_values);
    }

    /**
     * @dataProvider unsafe_form_urls
     */
    public function test_admin_cannot_save_a_url_that_is_not_public(string $url, string $message): void
    {
        wp_set_current_user($this->admin_id);

        $redirect = (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'create',
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_create'),
            'url' => $url,
            'events' => ['ticket.created'],
        ]);

        $this->assertStringContainsString('message='.$message, $redirect);
        $this->assertSame([], Webhook::all());
    }

    public static function unsafe_form_urls(): array
    {
        return [
            'private dns' => ['https://internal.example.com/hook', 'unsafe_url'],
            'loopback' => ['http://127.0.0.1/hook', 'unsafe_url'],
            'metadata' => ['http://169.254.169.254/', 'unsafe_url'],
            'javascript' => ['javascript:alert(1)', 'invalid_url'],
        ];
    }

    public function test_admin_must_pick_an_event(): void
    {
        wp_set_current_user($this->admin_id);

        $redirect = (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'create',
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_create'),
            'url' => 'https://hooks.example.com/new',
            'events' => ['not.an.event'],
        ]);

        $this->assertStringContainsString('message=no_events', $redirect);
        $this->assertSame([], Webhook::all());
    }

    public function test_updating_with_a_blank_secret_keeps_it_and_toggles_active(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook('https://hooks.example.com/a', ['ticket.created'], 'keep-me');

        (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'update',
            'id' => (string) $id,
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_update_'.$id),
            'url' => 'https://other.example.com/b',
            'secret' => '',
            'events' => ['reply.created'],
        ]);

        $hook = Webhook::find($id);
        $this->assertSame('https://other.example.com/b', $hook->url);
        $this->assertSame('keep-me', $hook->secret);
        $this->assertSame(0, (int) $hook->active);
        $this->assertSame(['reply.created'], Webhook::subscribed_events($hook));
    }

    public function test_deleting_a_webhook_removes_its_deliveries(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook('https://hooks.example.com/a', ['ticket.created']);
        $this->make_ticket();
        $this->assertSame(1, WebhookDelivery::count_for_webhook($id));

        (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'delete',
            'id' => (string) $id,
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_delete_'.$id),
        ]);

        $this->assertNull(Webhook::find($id));
        $this->assertSame(0, WebhookDelivery::count_for_webhook($id));
    }

    public function test_retry_queues_a_new_delivery_of_the_same_event(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook('https://hooks.example.com/a', ['ticket.created']);
        $this->status['https://hooks.example.com/a'] = 500;
        $ticket = $this->make_ticket();
        $dispatcher = new WebhookDispatcher;
        $dispatcher->process_due();
        $first = WebhookDelivery::latest_for_webhook($id);

        $this->status['https://hooks.example.com/a'] = 200;
        (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'retry',
            'id' => (string) $first->id,
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_retry_'.$first->id),
        ]);
        $retry = WebhookDelivery::latest_for_webhook($id);
        $this->assertNotSame((int) $first->id, (int) $retry->id);

        $this->make_due($first->id, 'failed');
        $dispatcher->process_due();

        $retry = WebhookDelivery::find($retry->id);
        $this->assertSame('delivered', $retry->status);
        $this->assertSame('ticket.created', $retry->event);
        $last = $this->calls('https://hooks.example.com/a');
        $this->assertSame((int) $ticket->id, end($last)['body']['payload']['ticket']['id']);
    }

    public function test_the_screens_render_the_webhooks_and_their_delivery_log(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook('https://hooks.example.com/listed', ['ticket.created'], 'never-shown');
        $this->status['https://hooks.example.com/listed'] = 500;
        $this->make_ticket();
        (new WebhookDispatcher)->process_due();

        $_GET = [];
        ob_start();
        (new Admin_Webhooks)->render();
        $list = ob_get_clean();

        $this->assertStringContainsString('https://hooks.example.com/listed', $list);
        $this->assertStringNotContainsString('never-shown', $list);
        $this->assertStringContainsString('value="ticket.tag_added"', $list);

        $_GET = ['action' => 'deliveries', 'id' => (string) $id];
        ob_start();
        (new Admin_Webhooks)->render();
        $log = ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString('ticket.created', $log);
        $this->assertStringContainsString('500', $log);
        $this->assertStringContainsString('name="escalated_webhook_action" value="retry"', $log);
    }

    public function test_the_form_needs_a_valid_nonce(): void
    {
        wp_set_current_user($this->admin_id);
        $this->expectException(WPDieException::class);

        (new Admin_Webhooks)->process([
            'escalated_webhook_action' => 'create',
            '_escalated_nonce' => 'forged',
            'url' => 'https://hooks.example.com/new',
            'events' => ['ticket.created'],
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function webhook(string $url, array $events, string $secret = '', int $active = 1): int
    {
        return (int) Webhook::create(['url' => $url, 'events' => $events, 'secret' => $secret, 'active' => $active]);
    }

    /**
     * @return array<int, array{args: array, body: array}>
     */
    private function calls(string $url): array
    {
        $out = [];
        foreach ($this->http as $call) {
            if ($call['url'] === $url) {
                $out[] = ['args' => $call['args'], 'body' => json_decode($call['args']['body'], true)];
            }
        }

        return $out;
    }

    private function make_due(int $delivery_id, ?string $status = null): void
    {
        global $wpdb;
        $data = ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)];
        if ($status !== null) {
            $data['status'] = $status;
        }
        $wpdb->update(WebhookDelivery::table(), $data, ['id' => $delivery_id]);
    }

    private function make_ticket(): object
    {
        return $this->tickets->create($this->requester_id, [
            'subject' => 'Printer on fire',
            'description' => 'It is still on fire.',
            'priority' => 'low',
            'channel' => 'web',
        ]);
    }

    private function make_tag(): int
    {
        global $wpdb;
        $slug = 'wh-'.strtolower(wp_generate_password(8, false, false));
        $wpdb->insert(Tag::table(), [
            'name' => $slug,
            'slug' => $slug,
            'color' => '#336699',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id;
    }
}
