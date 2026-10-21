<?php

/**
 * The shared widget's API contract (@escalated-dev/escalated EscalatedWidget.vue).
 *
 * Each test sends exactly the request the widget sends (JSON bodies, the same
 * paths and query strings) through WP_REST_Server::serve_request() and checks
 * the response fields the widget reads.
 */

use Escalated\Models\Article;
use Escalated\Models\ArticleCategory;
use Escalated\Models\ChatSession;
use Escalated\Models\Department;
use Escalated\Models\SatisfactionRating;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\TicketService;

class Test_Widget_Contract extends WP_UnitTestCase
{
    private const BASE = '/escalated/v1/widget';

    private const SHOP = 'https://shop.example.com';

    /** @var array<string, mixed> */
    private array $saved_server = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '1');

        $this->saved_server = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.'.wp_rand(1, 250);
    }

    public function tear_down(): void
    {
        remove_all_filters('escalated_widget_allowed_origins');
        $_SERVER = $this->saved_server;
        $_GET = [];
        unset($GLOBALS['HTTP_RAW_POST_DATA']);

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    /**
     * Send one request the way the widget's api() helper does.
     *
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, json: mixed}
     */
    private function api(string $method, string $path, ?array $json = null, array $query = [], array $headers = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $GLOBALS['HTTP_RAW_POST_DATA'] = $json !== null ? wp_json_encode($json) : '';
        $_GET = $query;

        global $wp_rest_server;
        $server = $wp_rest_server = new Spy_REST_Server;
        do_action('rest_api_init', $server);

        // Core's CORS handler calls header() directly, which the runner cannot do.
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        $server->serve_request(self::BASE.$path);
        add_filter('rest_pre_serve_request', 'rest_send_cors_headers');

        foreach (array_keys($headers) as $name) {
            unset($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))]);
        }
        $_GET = [];

        return [
            'status' => (int) $server->status,
            'headers' => $server->sent_headers,
            'json' => json_decode((string) $server->sent_body, true),
        ];
    }

    private function department(string $name, int $active = 1): int
    {
        return (int) Department::create([
            'name' => $name,
            'slug' => sanitize_title($name).'-'.wp_generate_password(6, false),
            'description' => '',
            'is_active' => $active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function start_chat(array $extra = []): array
    {
        $response = $this->api('POST', '/chat/start', array_merge([
            'name' => 'Wendy Widget',
            'email' => 'wendy@example.com',
            'department_id' => null,
            'message' => 'My order is late.',
        ], escalated_test_guest_proof((string) ($extra['email'] ?? 'wendy@example.com'), 'chat'), $extra));
        $this->assertSame(201, $response['status'], (string) wp_json_encode($response['json']));

        return $response['json'];
    }

    // ---------------------------------------------------------------------
    // GET /config
    // ---------------------------------------------------------------------

    public function test_config_has_the_keys_the_widget_reads(): void
    {
        $sales = $this->department('Sales');
        $this->department('Retired', 0);

        $response = $this->api('GET', '/config');
        $config = $response['json'];

        $this->assertSame(200, $response['status']);
        $this->assertTrue($config['enabled']);
        $this->assertSame([['id' => $sales, 'name' => 'Sales']], array_values(array_filter(
            $config['departments'],
            static fn ($d) => in_array($d['name'], ['Sales', 'Retired'], true)
        )));
        $this->assertTrue($config['kb_enabled']);
        $this->assertTrue($config['guest_tickets_enabled']);
        $this->assertTrue($config['accepts_metadata']);
        $this->assertTrue($config['status_lookup_enabled']);
        $this->assertTrue($config['guest_verification_required']);
        // Existing keys are unchanged.
        $this->assertArrayHasKey('color', $config);
        $this->assertArrayHasKey('position', $config);
        $this->assertArrayHasKey('greeting', $config);
    }

    public function test_config_reports_the_knowledge_base_switch(): void
    {
        Setting::set('knowledge_base_enabled', '0');
        $this->assertFalse($this->api('GET', '/config')['json']['kb_enabled']);

        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '0');
        $this->assertFalse($this->api('GET', '/config')['json']['kb_enabled']);
    }

    public function test_config_for_a_disabled_widget_is_403_enabled_false(): void
    {
        Setting::set('widget_enabled', '0');

        $response = $this->api('GET', '/config');

        $this->assertSame(403, $response['status']);
        $this->assertFalse($response['json']['enabled']);
    }

    // ---------------------------------------------------------------------
    // Knowledge base
    // ---------------------------------------------------------------------

    public function test_article_search_with_q_returns_an_articles_envelope(): void
    {
        Article::create(['title' => 'Track your parcel', 'slug' => 'track-your-parcel', 'body' => '<p>Use the tracking link.</p>', 'status' => 'published', 'published_at' => current_time('mysql')]);
        Article::create(['title' => 'Billing basics', 'slug' => 'billing-basics', 'body' => 'Invoices.', 'status' => 'published', 'published_at' => current_time('mysql')]);

        $response = $this->api('GET', '/articles', null, ['q' => 'parcel']);

        $this->assertSame(200, $response['status']);
        $this->assertCount(1, $response['json']['articles']);
        $article = $response['json']['articles'][0];
        $this->assertSame('Track your parcel', $article['title']);
        $this->assertSame('track-your-parcel', $article['slug']);
        $this->assertSame('Use the tracking link.', $article['excerpt']);
    }

    public function test_article_search_keeps_the_search_param_and_bare_array(): void
    {
        Article::create(['title' => 'Track your parcel', 'slug' => 'track-your-parcel', 'body' => 'x', 'status' => 'published', 'published_at' => current_time('mysql')]);

        $response = $this->api('GET', '/articles', null, ['search' => 'parcel']);

        $this->assertSame(200, $response['status']);
        $this->assertSame('track-your-parcel', $response['json'][0]['slug']);
    }

    public function test_article_returns_body_slug_and_category(): void
    {
        $category = ArticleCategory::create(['name' => 'Shipping', 'slug' => 'shipping']);
        Article::create(['title' => 'Track your parcel', 'slug' => 'track-your-parcel', 'body' => '<p>Use the tracking link.</p>', 'category_id' => $category, 'status' => 'published', 'published_at' => current_time('mysql')]);

        $response = $this->api('GET', '/articles/track-your-parcel');
        $article = $response['json'];

        $this->assertSame(200, $response['status']);
        $this->assertSame('Track your parcel', $article['title']);
        $this->assertSame('track-your-parcel', $article['slug']);
        $this->assertSame('<p>Use the tracking link.</p>', $article['body']);
        $this->assertSame('Shipping', $article['category']['name']);
        $this->assertSame($article['body'], $article['content'], 'content stays for existing clients');
    }

    // ---------------------------------------------------------------------
    // POST /tickets
    // ---------------------------------------------------------------------

    public function test_ticket_create_accepts_the_widget_body_with_a_department(): void
    {
        $sales = $this->department('Sales');

        $response = $this->api('POST', '/tickets', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Where is my order?',
            'description' => 'It has not arrived.',
            'department_id' => $sales,
        ] + escalated_test_guest_proof('jane@example.com'));

        $this->assertSame(201, $response['status']);
        $this->assertNotEmpty($response['json']['message']);
        $ticket = Ticket::find_by_reference($response['json']['reference']);
        $this->assertSame($sales, (int) $ticket->department_id);
        $this->assertStringStartsWith('ega1.', $response['json']['guest_access_token']);
        $this->assertNotSame($ticket->guest_token, $response['json']['guest_access_token']);
        $this->assertSame($ticket->guest_token, $response['json']['guest_token']);
    }

    public function test_ticket_create_accepts_a_null_department_and_ignores_unknown_ones(): void
    {
        $retired = $this->department('Retired', 0);

        foreach ([null, 999999, $retired] as $department_id) {
            $response = $this->api('POST', '/tickets', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'subject' => 'Where is my order?',
                'description' => 'It has not arrived.',
                'department_id' => $department_id,
            ] + escalated_test_guest_proof('jane@example.com'));

            $this->assertSame(201, $response['status'], (string) wp_json_encode($response['json']));
            $this->assertNull(Ticket::find_by_reference($response['json']['reference'])->department_id);
        }
    }

    // ---------------------------------------------------------------------
    // Live chat
    // ---------------------------------------------------------------------

    public function test_chat_start_returns_an_unguessable_id(): void
    {
        $chat = $this->start_chat();

        $this->assertIsString($chat['id']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $chat['id']);
        $this->assertDoesNotMatchRegularExpression('/^\d+$/', $chat['id']);
        $this->assertSame($chat['guest_token'], $chat['id']);
        $this->assertIsInt($chat['session_id']);
        $this->assertNotNull(ChatSession::find($chat['session_id']));
        $this->assertSame('waiting', $chat['status']);
        $this->assertSame('My order is late.', $chat['messages'][0]['body']);
        $this->assertFalse($chat['messages'][0]['is_agent']);
    }

    public function test_chat_start_stores_a_valid_department_only(): void
    {
        $sales = $this->department('Sales');

        $chat = $this->start_chat(['department_id' => $sales]);
        $this->assertSame($sales, (int) ChatSession::find($chat['session_id'])->department_id);

        $chat = $this->start_chat(['department_id' => 999999]);
        $this->assertEmpty(ChatSession::find($chat['session_id'])->department_id);
    }

    public function test_chat_messages_round_trip_by_id(): void
    {
        $chat = $this->start_chat();

        $sent = $this->api('POST', '/chat/'.$chat['id'].'/messages', ['body' => 'Any news?']);
        $this->assertGreaterThanOrEqual(200, $sent['status']);
        $this->assertLessThan(300, $sent['status']);

        $agent = self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Alex Agent']);
        ChatSession::update($chat['session_id'], ['agent_id' => $agent, 'status' => 'active']);
        (new TicketService)->reply((int) ChatSession::find($chat['session_id'])->ticket_id, $agent, 'It ships today.');

        $response = $this->api('GET', '/chat/'.$chat['id'].'/messages');
        $data = $response['json'];

        $this->assertSame(200, $response['status']);
        $bodies = array_column($data['messages'], 'body');
        $this->assertSame(['My order is late.', 'Any news?', 'It ships today.'], $bodies);

        $visitor = $data['messages'][1];
        $this->assertFalse($visitor['is_agent']);
        $this->assertSame('Wendy Widget', $visitor['author']['name']);
        $this->assertNotEmpty($visitor['id']);
        $this->assertNotEmpty($visitor['created_at']);

        $reply = $data['messages'][2];
        $this->assertTrue($reply['is_agent']);
        $this->assertSame('Alex Agent', $reply['author']['name']);

        $this->assertSame(['name' => 'Alex Agent'], $data['agent']);
        $this->assertArrayHasKey('typing', $data);
        $this->assertFalse($data['ended']);
    }

    public function test_chat_typing_end_and_rate(): void
    {
        $chat = $this->start_chat();

        $this->assertSame(200, $this->api('POST', '/chat/'.$chat['id'].'/typing')['status']);

        $early = $this->api('POST', '/chat/'.$chat['id'].'/rate', ['rating' => 5, 'comment' => '']);
        $this->assertSame(422, $early['status'], 'a chat is rated after it ends');

        $this->assertSame(200, $this->api('POST', '/chat/'.$chat['id'].'/end')['status']);
        $this->assertTrue($this->api('GET', '/chat/'.$chat['id'].'/messages')['json']['ended']);

        $closed = $this->api('POST', '/chat/'.$chat['id'].'/messages', ['body' => 'Hello?']);
        $this->assertSame(422, $closed['status']);

        $rated = $this->api('POST', '/chat/'.$chat['id'].'/rate', ['rating' => 4, 'comment' => 'Quick answer.']);
        $this->assertSame(200, $rated['status'], (string) wp_json_encode($rated['json']));

        $rating = SatisfactionRating::for_ticket((int) ChatSession::find($chat['session_id'])->ticket_id);
        $this->assertSame(4, (int) $rating->rating);
        $this->assertSame('Quick answer.', $rating->comment);

        $bad = $this->api('POST', '/chat/'.$chat['id'].'/rate', ['rating' => 9]);
        $this->assertSame(400, $bad['status']);
    }

    public function test_chat_routes_reject_unknown_ids(): void
    {
        $unknown = wp_generate_password(64, false);

        $this->assertSame(404, $this->api('GET', '/chat/'.$unknown.'/messages')['status']);
        $this->assertSame(404, $this->api('POST', '/chat/'.$unknown.'/messages', ['body' => 'x'])['status']);
        $this->assertSame(404, $this->api('POST', '/chat/'.$unknown.'/typing')['status']);
        $this->assertSame(404, $this->api('POST', '/chat/'.$unknown.'/end')['status']);
        $this->assertSame(404, $this->api('POST', '/chat/'.$unknown.'/rate', ['rating' => 5])['status']);
    }

    public function test_chat_id_of_a_non_chat_ticket_is_rejected(): void
    {
        $ticket = (new TicketService)->create_guest([
            'subject' => 'Email ticket',
            'description' => 'Not a chat.',
            'guest_name' => 'Jane',
            'guest_email' => 'jane@example.com',
        ]);

        $this->assertSame(404, $this->api('GET', '/chat/'.$ticket->guest_token.'/messages')['status']);
    }

    public function test_legacy_numeric_chat_routes_still_work(): void
    {
        $chat = $this->start_chat();

        $_GET = [];
        $sent = $this->api('POST', '/chat/'.$chat['session_id'].'/messages', ['body' => 'Legacy', 'guest_token' => $chat['guest_token']]);
        $this->assertSame(201, $sent['status']);

        $poll = $this->api('GET', '/chat/'.$chat['session_id'].'/poll', null, ['guest_token' => $chat['guest_token'], 'since' => '2000-01-01 00:00:00']);
        $this->assertSame(200, $poll['status']);
        $this->assertSame('waiting', $poll['json']['session_status']);

        $denied = $this->api('POST', '/chat/'.$chat['session_id'].'/end', ['guest_token' => 'wrong']);
        $this->assertSame(403, $denied['status']);
    }

    public function test_new_chat_routes_get_cors_for_allowed_sites(): void
    {
        add_filter('escalated_widget_allowed_origins', static fn (array $o) => array_merge($o, [self::SHOP]));
        $chat = $this->start_chat();

        $preflight = $this->api('OPTIONS', '/chat/'.$chat['id'].'/messages', null, [], [
            'Origin' => self::SHOP,
            'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertSame(204, $preflight['status']);
        $this->assertSame(self::SHOP, $preflight['headers']['Access-Control-Allow-Origin'] ?? null);

        foreach (['messages', 'typing'] as $action) {
            $response = $this->api('POST', '/chat/'.$chat['id'].'/'.$action, ['body' => 'Hi'], [], ['Origin' => self::SHOP]);
            $this->assertLessThan(300, $response['status']);
            $this->assertSame(self::SHOP, $response['headers']['Access-Control-Allow-Origin'] ?? null);
        }

        $missing = $this->api('GET', '/chat/'.wp_generate_password(64, false).'/messages', null, [], ['Origin' => self::SHOP]);
        $this->assertSame(404, $missing['status']);
        $this->assertSame(self::SHOP, $missing['headers']['Access-Control-Allow-Origin'] ?? null);
    }
}
