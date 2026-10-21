<?php

/**
 * Ticket merge, after escalated-laravel's TicketMergeService: the REST
 * routes (with an API token), the wp-admin action, and the service's
 * effects and refusals.
 */

use Escalated\Admin\Admin_Tickets;
use Escalated\Escalated;
use Escalated\Models\ApiToken;
use Escalated\Models\Reply;
use Escalated\Models\Ticket;
use Escalated\Services\TicketService;

class Test_Ticket_Merge extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    private TicketService $tickets;

    private int $admin_id;

    private string $token;

    private int $requester_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->tickets = new TicketService;
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        get_role('administrator')->add_cap('escalated_ticket_merge');
        $this->requester_id = $this->factory->user->create(['role' => 'subscriber']);
        $this->token = ApiToken::create_token($this->admin_id, 'merge test')['token'];

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user(0);

        parent::tear_down();
    }

    public function test_merge_moves_replies_notes_both_tickets_and_closes_the_source(): void
    {
        $source = $this->make_ticket('Duplicate');
        $target = $this->make_ticket('Original');
        $this->tickets->reply((int) $source->id, $this->requester_id, 'Same problem here.');
        $this->tickets->add_note((int) $source->id, $this->admin_id, 'Looks like a duplicate.');

        $closed = [];
        add_action('escalated_ticket_closed', function ($ticket) use (&$closed) {
            $closed[] = (int) $ticket->id;
        });
        $merged = null;
        add_action('escalated_ticket_merged', function ($source, $target) use (&$merged) {
            $merged = [(int) $source->id, (int) $target->id];
        }, 10, 2);

        $response = $this->api('POST', '/tickets/'.$source->reference.'/merge', ['target_reference' => $target->reference]);

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame($target->reference, $response->get_data()['ticket']->reference);

        $source_after = Ticket::find((int) $source->id);
        $this->assertSame('closed', $source_after->status);
        $this->assertSame((int) $target->id, (int) $source_after->merged_into_id);
        $this->assertNotEmpty($source_after->closed_at);

        $target_bodies = array_column(Reply::for_ticket((int) $target->id), 'body');
        $this->assertContains('Same problem here.', $target_bodies);
        $this->assertContains('Looks like a duplicate.', $target_bodies);
        $this->assertContains('Ticket '.$source->reference.' was merged into this ticket.', $target_bodies);

        $source_replies = Reply::for_ticket((int) $source->id);
        $this->assertCount(1, $source_replies);
        $this->assertSame('This ticket was merged into '.$target->reference.'.', $source_replies[0]->body);
        $this->assertSame(1, (int) $source_replies[0]->is_internal_note);
        $meta = json_decode($source_replies[0]->metadata, true);
        $this->assertTrue($meta['system_note']);
        $this->assertSame($target->reference, $meta['merge_target']);
        $this->assertSame($this->admin_id, $meta['merged_by']);

        $this->assertSame([(int) $source->id], $closed);
        $this->assertSame([(int) $source->id, (int) $target->id], $merged);
    }

    public function test_merge_refusals(): void
    {
        $a = $this->make_ticket('A');
        $b = $this->make_ticket('B');
        $c = $this->make_ticket('C');

        $self = $this->api('POST', '/tickets/'.$a->reference.'/merge', ['target_reference' => $a->reference]);
        $this->assertSame(422, $self->get_status());

        $missing = $this->api('POST', '/tickets/'.$a->reference.'/merge', ['target_reference' => 'ESC-99999999']);
        $this->assertSame(404, $missing->get_status());

        $this->assertSame(200, $this->api('POST', '/tickets/'.$a->reference.'/merge', ['target_reference' => $b->reference])->get_status());

        $again = $this->api('POST', '/tickets/'.$a->reference.'/merge', ['target_reference' => $c->reference]);
        $this->assertSame(422, $again->get_status());

        $into_merged = $this->api('POST', '/tickets/'.$c->reference.'/merge', ['target_reference' => $a->reference]);
        $this->assertSame(404, $into_merged->get_status());
        $this->assertNull(Ticket::find((int) $c->id)->merged_into_id);
    }

    public function test_merge_needs_the_merge_permission_and_a_token(): void
    {
        $a = $this->make_ticket('A');
        $b = $this->make_ticket('B');

        $request = new WP_REST_Request('POST', '/escalated/v1/tickets/'.$a->reference.'/merge');
        $request->set_param('target_reference', $b->reference);
        $this->assertSame(401, $this->server->dispatch($request)->get_status());

        $agent = $this->factory->user->create(['role' => 'escalated_light_agent']);
        $agent_token = ApiToken::create_token($agent, 'no merge')['token'];
        $response = $this->api('POST', '/tickets/'.$a->reference.'/merge', ['target_reference' => $b->reference], $agent_token);
        $this->assertContains($response->get_status(), [401, 403]);
        $this->assertNull(Ticket::find((int) $a->id)->merged_into_id);
    }

    public function test_merge_search_finds_unmerged_tickets_and_escapes_wildcards(): void
    {
        // A word no other test uses: merges commit, so rows from other
        // tests can be visible here.
        $word = 'Zq'.strtolower(wp_generate_password(8, false, false));
        $refund = $this->make_ticket($word.' order 100% late');
        $other = $this->make_ticket($word.' please');
        $merged = $this->make_ticket($word.' duplicate');
        $this->api('POST', '/tickets/'.$merged->reference.'/merge', ['target_reference' => $other->reference]);

        $found = $this->api('GET', '/tickets/merge-search', ['q' => $word, 'exclude' => $other->reference])->get_data()['tickets'];
        $this->assertSame([$refund->reference], array_column($found, 'reference'));

        $percent = $this->api('GET', '/tickets/merge-search', ['q' => $word.' order 100%'])->get_data()['tickets'];
        $this->assertSame([$refund->reference], array_column($percent, 'reference'));
        foreach ($this->api('GET', '/tickets/merge-search', ['q' => '%'])->get_data()['tickets'] as $hit) {
            $this->assertStringContainsString('%', $hit['subject'], 'A bare % matched everything.');
        }

        $by_reference = $this->api('GET', '/tickets/merge-search', ['q' => $other->reference])->get_data()['tickets'];
        $this->assertSame($other->reference, $by_reference[0]['reference']);
        $this->assertSame(['id', 'reference', 'subject', 'status', 'created_at'], array_keys($by_reference[0]));
    }

    public function test_the_ticket_screen_merges_and_shows_where_it_went(): void
    {
        wp_set_current_user($this->admin_id);
        $source = $this->make_ticket('Duplicate');
        $target = $this->make_ticket('Original');

        $redirect = (new Admin_Tickets)->merge((int) $source->id, strtolower($target->reference), $this->admin_id, 'back');
        $this->assertStringContainsString('ticket_id='.$target->id, $redirect);
        $this->assertStringContainsString('message=merged', $redirect);
        $this->assertSame((int) $target->id, (int) Ticket::find((int) $source->id)->merged_into_id);

        $this->assertStringContainsString('message=merge_not_found', (new Admin_Tickets)->merge((int) $target->id, 'ESC-0', $this->admin_id, 'back'));

        $_GET = ['page' => 'escalated', 'action' => 'view', 'ticket_id' => (string) $source->id];
        ob_start();
        (new Admin_Tickets)->render_list();
        $html = ob_get_clean();
        $_GET = [];
        $this->assertStringContainsString('Merged into', $html);
        $this->assertStringContainsString($target->reference, $html);
    }

    public function test_a_failed_merge_changes_nothing(): void
    {
        global $wpdb;
        $source = $this->make_ticket('Duplicate');
        $target = $this->make_ticket('Original');
        $this->tickets->reply((int) $source->id, $this->requester_id, 'Stays put.');

        // Make the second write fail: notes cannot be inserted.
        $fail = static fn ($query) => (stripos($query, 'INSERT INTO `'.Reply::table().'`') !== false) ? 'INSERT INTO no_such_table_for_merge VALUES (1)' : $query;
        add_filter('query', $fail);
        $suppress = $wpdb->suppress_errors(true);
        try {
            (new \Escalated\Services\TicketMergeService)->merge($source, $target, $this->admin_id);
            $this->fail('The merge did not fail.');
        } catch (\RuntimeException $e) {
            // Expected.
        } finally {
            remove_filter('query', $fail);
            $wpdb->suppress_errors($suppress);
        }

        $this->assertNull(Ticket::find((int) $source->id)->merged_into_id);
        $this->assertContains('Stays put.', array_column(Reply::for_ticket((int) $source->id), 'body'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function api(string $method, string $path, array $params = [], ?string $token = null): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/escalated/v1'.$path);
        $request->set_header('Authorization', 'Bearer '.($token ?? $this->token));
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_ensure_response($this->server->dispatch($request));
    }

    private function make_ticket(string $subject): object
    {
        return $this->tickets->create($this->requester_id, [
            'subject' => $subject,
            'description' => 'Details',
            'priority' => 'low',
        ]);
    }
}
