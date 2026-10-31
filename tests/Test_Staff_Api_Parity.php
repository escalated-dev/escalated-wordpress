<?php

/**
 * The staff REST API (escalated/v1) accepts what escalated-laravel's agent
 * API (support/api/v1) accepts.
 *
 * An internal note posted to /reply with is_internal_note stays internal: it
 * is stored as a note, fires note.created (never reply.created) and never
 * emails the customer. Status and priority take PATCH as well as POST, and
 * /tags takes Laravel's full tag_ids set. Everything goes through the REST
 * router and the hooks the plugin registers at boot.
 */

use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Services\TicketService;

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
}
