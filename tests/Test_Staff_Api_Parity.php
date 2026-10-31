<?php

/**
 * The staff REST API (escalated/v1) accepts what escalated-laravel's agent
 * API (support/api/v1) accepts.
 *
 * An internal note posted to /reply with is_internal_note stays internal: it
 * is stored as a note, fires note.created (never reply.created) and never
 * emails the customer. Status and priority take PATCH as well as POST, and
 * /tags takes Laravel's full tag_ids set. Status and priority changes go
 * through TicketService, as the wp-admin ticket screen does, so they fire
 * the same hooks (workflows, webhooks, activity log, notifications).
 * Everything goes through the REST router and the hooks the plugin
 * registers at boot.
 */

use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Webhook;
use Escalated\Services\TicketService;
use Escalated\Services\WebhookDispatcher;

class Test_Staff_Api_Parity extends WP_UnitTestCase
{
    private int $agent_id;

    private int $requester_id;

    private string $requester_email = 'customer@example.com';

    private string $token;

    private WP_REST_Server $server;

    /** @var array<int, string> */
    private array $mailed = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->agent_id = $this->factory->user->create(['role' => 'administrator', 'user_email' => 'agent@example.com']);
        $this->requester_id = $this->factory->user->create(['role' => 'subscriber', 'user_email' => $this->requester_email]);
        $this->token = $this->make_token(['*']);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');

        $this->mailed = [];
        add_filter('pre_wp_mail', [$this, 'capture_mail'], 10, 2);
    }

    public function tear_down(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture_mail'], 10);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function capture_mail($return, $atts)
    {
        foreach ((array) $atts['to'] as $to) {
            $this->mailed[] = (string) $to;
        }

        return true;
    }

    private function make_token(array $abilities): string
    {
        global $wpdb;
        $plain = wp_generate_password(64, false);
        $wpdb->insert(\Escalated\Escalated::table('api_tokens'), [
            'user_id' => $this->agent_id,
            'name' => 'Parity token',
            'token' => hash('sha256', $plain),
            'abilities' => wp_json_encode($abilities),
            'created_at' => current_time('mysql'),
        ]);

        return $plain;
    }

    private function request(string $method, string $route, array $params = [], ?string $token = null): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/escalated/v1/'.$route);
        $request->set_header('Authorization', 'Bearer '.($token ?? $this->token));
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $this->server->dispatch($request);
    }

    private function ticket(): object
    {
        return (new TicketService)->create($this->requester_id, [
            'subject' => 'Parity ticket',
            'description' => 'Customer question.',
            'priority' => 'medium',
        ]);
    }

    // =========================================================================
    // Internal notes posted to /reply
    // =========================================================================

    public function test_reply_with_is_internal_note_is_stored_as_an_internal_note(): void
    {
        $ticket = $this->ticket();

        $response = $this->request('POST', 'tickets/'.$ticket->reference.'/reply', [
            'body' => 'Secret: customer is on the watch list.',
            'is_internal_note' => true,
        ]);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(1, (int) $data['reply']->is_internal_note);
        $this->assertSame('note', $data['reply']->type);
        $this->assertTrue((bool) $data['data']['is_internal_note']);

        $public = Reply::for_ticket((int) $ticket->id, false);
        $this->assertSame([], array_values(array_filter($public, fn ($r) => str_contains((string) $r->body, 'Secret'))));
    }

    public function test_internal_note_on_reply_fires_note_created_not_reply_created_and_never_emails_the_customer(): void
    {
        $ticket = $this->ticket();
        $replies_before = did_action('escalated_reply_created');
        $notes_before = did_action('escalated_internal_note_added');
        $this->mailed = [];

        $this->request('POST', 'tickets/'.$ticket->reference.'/reply', [
            'body' => 'Internal only.',
            'is_internal_note' => 'true',
        ]);

        $this->assertSame($replies_before, did_action('escalated_reply_created'));
        $this->assertSame($notes_before + 1, did_action('escalated_internal_note_added'));
        $this->assertNotContains($this->requester_email, $this->mailed);
    }

    public function test_internal_note_on_reply_needs_the_note_ability(): void
    {
        $ticket = $this->ticket();
        $reply_only = $this->make_token(['tickets:reply']);

        $response = $this->request('POST', 'tickets/'.$ticket->reference.'/reply', [
            'body' => 'Should not land.',
            'is_internal_note' => '1',
        ], $reply_only);

        $this->assertContains($response->get_status(), [401, 403]);
        $this->assertSame([], Reply::for_ticket((int) $ticket->id));
    }

    public function test_public_reply_fires_reply_created_and_emails_the_customer(): void
    {
        $ticket = $this->ticket();
        $replies_before = did_action('escalated_reply_created');
        $this->mailed = [];

        $response = $this->request('POST', 'tickets/'.$ticket->reference.'/reply', [
            'body' => 'Here is the fix.',
            'is_internal_note' => false,
        ]);

        $this->assertSame(201, $response->get_status());
        $this->assertSame(0, (int) $response->get_data()['reply']->is_internal_note);
        $this->assertSame($replies_before + 1, did_action('escalated_reply_created'));
        $this->assertContains($this->requester_email, $this->mailed);
    }

    public function test_note_endpoint_fires_note_created_and_never_emails_the_customer(): void
    {
        $ticket = $this->ticket();
        $replies_before = did_action('escalated_reply_created');
        $notes_before = did_action('escalated_internal_note_added');
        $this->mailed = [];

        $response = $this->request('POST', 'tickets/'.$ticket->reference.'/note', ['body' => 'Note.']);

        $this->assertSame(201, $response->get_status());
        $this->assertSame($replies_before, did_action('escalated_reply_created'));
        $this->assertSame($notes_before + 1, did_action('escalated_internal_note_added'));
        $this->assertNotContains($this->requester_email, $this->mailed);
    }

    // =========================================================================
    // Status and priority take PATCH, as Laravel's API
    // =========================================================================

    public function test_status_accepts_patch(): void
    {
        $ticket = $this->ticket();

        $response = $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'in_progress']);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('in_progress', $response->get_data()['status']);
        $this->assertSame('in_progress', $response->get_data()['ticket']->status);
    }

    public function test_priority_accepts_patch(): void
    {
        $ticket = $this->ticket();

        $response = $this->request('PATCH', 'tickets/'.$ticket->reference.'/priority', ['priority' => 'urgent']);

        $this->assertSame(200, $response->get_status());
        $this->assertSame('urgent', $response->get_data()['priority']);
        $this->assertSame('urgent', $response->get_data()['ticket']->priority);
    }

    public function test_status_and_priority_still_accept_post(): void
    {
        $ticket = $this->ticket();

        $this->assertSame(200, $this->request('POST', 'tickets/'.$ticket->reference.'/status', ['status' => 'in_progress'])->get_status());
        $this->assertSame(200, $this->request('POST', 'tickets/'.$ticket->reference.'/priority', ['priority' => 'high'])->get_status());
    }

    // =========================================================================
    // Tags take Laravel's full tag_ids set
    // =========================================================================

    public function test_tags_accepts_a_full_tag_ids_set(): void
    {
        $ticket = $this->ticket();
        $keep = Tag::create(['name' => 'Keep', 'slug' => 'keep']);
        $drop = Tag::create(['name' => 'Drop', 'slug' => 'drop']);
        $add = Tag::create(['name' => 'Add', 'slug' => 'add']);
        Tag::attach((int) $ticket->id, $keep);
        Tag::attach((int) $ticket->id, $drop);

        $response = $this->request('POST', 'tickets/'.$ticket->reference.'/tags', ['tag_ids' => [$keep, $add]]);

        $this->assertSame(200, $response->get_status());
        $ids = array_map(fn ($t) => (int) $t->id, Tag::for_ticket((int) $ticket->id));
        sort($ids);
        $expected = [$keep, $add];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_tags_with_unknown_tag_id_is_rejected(): void
    {
        $ticket = $this->ticket();

        $response = $this->request('POST', 'tickets/'.$ticket->reference.'/tags', ['tag_ids' => [999999]]);

        $this->assertSame(422, $response->get_status());
    }

    public function test_tags_add_and_remove_still_work(): void
    {
        $ticket = $this->ticket();
        $a = Tag::create(['name' => 'A', 'slug' => 'a']);

        $this->request('POST', 'tickets/'.$ticket->reference.'/tags', ['add' => [$a]]);
        $this->assertCount(1, Tag::for_ticket((int) $ticket->id));

        $this->request('POST', 'tickets/'.$ticket->reference.'/tags', ['remove' => [$a]]);
        $this->assertCount(0, Tag::for_ticket((int) $ticket->id));
    }

    // =========================================================================
    // List takes Laravel's following filter
    // =========================================================================

    public function test_list_with_following_returns_only_tickets_the_caller_follows(): void
    {
        $followed = $this->ticket();
        $other = $this->ticket();
        $this->request('POST', 'tickets/'.$followed->reference.'/follow');

        $response = $this->request('GET', 'tickets', ['following' => '1']);

        $this->assertSame(200, $response->get_status());
        $refs = array_map(fn ($t) => $t->reference, $response->get_data()['items']);
        $this->assertSame([$followed->reference], $refs);
        $this->assertNotContains($other->reference, $refs);
    }

    // =========================================================================
    // Status and priority changes fire the ticket screen's hooks
    // =========================================================================

    /**
     * Run $act with an all-events webhook subscribed and HTTP answered, then
     * send the queued deliveries and return the events that went out.
     *
     * @return array<int, string>
     */
    private function webhook_events(callable $act): array
    {
        Webhook::create([
            'url' => 'https://hooks.example.com/all',
            'events' => \Escalated\Services\EventVocabulary::WEBHOOK_EVENTS,
            'secret' => 's',
            'active' => 1,
        ]);
        $sent = [];
        $http = function ($preempt, $args, $url) use (&$sent) {
            $sent[] = json_decode($args['body'], true)['event'] ?? null;

            return ['headers' => [], 'body' => 'ok', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        };
        $dns = fn ($addresses, $host) => ['93.184.216.34'];
        add_filter('pre_http_request', $http, 10, 3);
        add_filter('escalated_outbound_url_resolve', $dns, 10, 2);

        $act();
        (new WebhookDispatcher)->process_due();

        remove_filter('pre_http_request', $http, 10);
        remove_filter('escalated_outbound_url_resolve', $dns, 10);

        return $sent;
    }

    /**
     * The hooks among $hooks that fired while $act ran, in order.
     *
     * @return array<int, string>
     */
    private function fired(array $hooks, callable $act): array
    {
        $fired = [];
        $listeners = [];
        foreach ($hooks as $hook) {
            $listeners[$hook] = function () use (&$fired, $hook) {
                $fired[] = $hook;
            };
            add_action($hook, $listeners[$hook]);
        }
        $act();
        foreach ($listeners as $hook => $listener) {
            remove_action($hook, $listener);
        }

        return $fired;
    }

    private function activity(int $ticket_id, string $type): array
    {
        global $wpdb;
        $table = \Escalated\Escalated::table('ticket_activities');

        // Changes only, not the entry ticket creation logs.
        return array_values(array_filter(
            array_map(
                fn ($row) => ['causer_id' => (int) $row->causer_id, 'properties' => json_decode((string) $row->properties, true)],
                $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE ticket_id = %d AND type = %s ORDER BY id", $ticket_id, $type))
            ),
            fn ($entry) => isset($entry['properties']['old_status']) || isset($entry['properties']['old_priority'])
        ));
    }

    public function test_status_change_fires_the_status_hooks_and_the_webhooks(): void
    {
        $ticket = $this->ticket();
        $response = null;
        $events = [];

        $fired = $this->fired(['escalated_ticket_status_changed', 'escalated_ticket_resolved'], function () use (&$events, &$response, $ticket) {
            $events = $this->webhook_events(function () use (&$response, $ticket) {
                $response = $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'resolved']);
            });
        });

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['escalated_ticket_status_changed', 'escalated_ticket_resolved'], $fired);
        $this->assertContains('ticket.status_changed', $events);
        $this->assertContains('ticket.resolved', $events);
        $this->assertNotEmpty(\Escalated\Models\Ticket::find((int) $ticket->id)->resolved_at);
    }

    public function test_status_change_logs_activity_the_way_the_ticket_screen_does(): void
    {
        $ticket = $this->ticket();

        $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'in_progress']);

        $this->assertSame(
            [['causer_id' => $this->agent_id, 'properties' => ['old_status' => 'open', 'new_status' => 'in_progress']]],
            $this->activity((int) $ticket->id, 'status_changed')
        );
    }

    public function test_closing_and_reopening_fire_their_hooks(): void
    {
        $ticket = $this->ticket();

        $fired = $this->fired(['escalated_ticket_closed', 'escalated_ticket_reopened'], function () use ($ticket) {
            $this->assertSame(200, $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'closed'])->get_status());
            $this->assertSame(200, $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'reopened'])->get_status());
        });

        $this->assertSame(['escalated_ticket_closed', 'escalated_ticket_reopened'], $fired);
    }

    public function test_invalid_status_transition_is_still_rejected_and_fires_nothing(): void
    {
        $ticket = $this->ticket();
        $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'closed']);

        $fired = $this->fired(['escalated_ticket_status_changed'], function () use ($ticket) {
            $this->assertSame(422, $this->request('PATCH', 'tickets/'.$ticket->reference.'/status', ['status' => 'in_progress'])->get_status());
        });

        $this->assertSame([], $fired);
    }

    public function test_priority_change_fires_the_priority_hook_and_the_webhook(): void
    {
        $ticket = $this->ticket();
        $response = null;
        $events = [];

        $fired = $this->fired(['escalated_ticket_priority_changed'], function () use (&$events, &$response, $ticket) {
            $events = $this->webhook_events(function () use (&$response, $ticket) {
                $response = $this->request('PATCH', 'tickets/'.$ticket->reference.'/priority', ['priority' => 'urgent']);
            });
        });

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['escalated_ticket_priority_changed'], $fired);
        $this->assertContains('ticket.priority_changed', $events);
        $this->assertSame(
            [['causer_id' => $this->agent_id, 'properties' => ['old_priority' => 'medium', 'new_priority' => 'urgent']]],
            $this->activity((int) $ticket->id, 'priority_changed')
        );
    }
}
