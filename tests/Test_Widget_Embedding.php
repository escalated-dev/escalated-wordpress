<?php

/**
 * Embedding the widget on other sites: CORS on the widget routes, guest
 * forcing, host parameters (metadata), the embed.js script and the admin
 * allow-list setting.
 *
 * Requests go through WP_REST_Server::serve_request() (Spy_REST_Server) so
 * the headers WordPress actually sends are checked, not only the response
 * object.
 */

use Escalated\Admin\Admin_Settings;
use Escalated\Models\ChatSession;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;

class Test_Widget_Embedding extends WP_UnitTestCase
{
    private const SHOP = 'https://shop.example.com';

    private const EVIL = 'https://evil.example.net';

    /** @var list<string> */
    private array $server_keys = [];

    /** @var array<string, mixed> */
    private array $saved_server = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        Setting::set('widget_enabled', '1');
        Setting::set('guest_tickets_enabled', '1');
        Setting::set('widget_allowed_origins', '');
        add_filter('escalated_widget_allowed_origins', [$this, 'shop_origin']);

        $this->saved_server = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.'.wp_rand(1, 250);
    }

    public function tear_down(): void
    {
        remove_filter('escalated_widget_allowed_origins', [$this, 'shop_origin']);
        $_SERVER = $this->saved_server;
        $_GET = [];
        unset($GLOBALS['HTTP_RAW_POST_DATA']);

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    public function shop_origin(array $origins): array
    {
        $origins[] = self::SHOP;

        return $origins;
    }

    /**
     * Serve one REST request the way WordPress does for a real HTTP request.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     * @return array{status: int, headers: array<string, string>, body: string, json: mixed, core_cors: bool}
     */
    private function serve(string $method, string $path, array $headers = [], ?array $json = null): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if ($json !== null) {
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode($json);
        } else {
            unset($_SERVER['CONTENT_TYPE']);
            $GLOBALS['HTTP_RAW_POST_DATA'] = '';
        }

        global $wp_rest_server;
        $server = $wp_rest_server = new Spy_REST_Server;
        do_action('rest_api_init', $server);

        // Whether WordPress core's own CORS handler would still run for this request.
        $core_cors = null;
        $probe = function ($served) use (&$core_cors) {
            $core_cors = has_filter('rest_pre_serve_request', 'rest_send_cors_headers') !== false;
            // Core's handler calls header() directly, which the test runner
            // cannot do once output has started; its presence is recorded above.
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

            return $served;
        };
        add_filter('rest_pre_serve_request', $probe, 9);

        $server->serve_request($path);
        $body = (string) $server->sent_body;

        remove_filter('rest_pre_serve_request', $probe, 9);
        add_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        foreach (array_keys($headers) as $name) {
            unset($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))]);
        }

        return [
            'status' => (int) $server->status,
            'headers' => $server->sent_headers,
            'body' => $body,
            'json' => json_decode($body, true),
            'core_cors' => (bool) $core_cors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket_payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Where is my order?',
            'description' => 'It has not arrived yet.',
        ], escalated_test_guest_proof('jane@example.com'), $extra);
    }

    private function ticket_metadata(string $reference): ?array
    {
        $ticket = Ticket::find_by_reference($reference);
        $this->assertNotNull($ticket);

        return $ticket->metadata === null ? null : json_decode($ticket->metadata, true);
    }

    // =========================================================================
    // CORS
    // =========================================================================

    public function test_answers_a_preflight_from_an_allowed_site(): void
    {
        $response = $this->serve('OPTIONS', '/escalated/v1/widget/tickets', [
            'Origin' => self::SHOP,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization',
        ]);

        $this->assertSame(204, $response['status']);
        $this->assertSame('', $response['body']);
        $this->assertSame(self::SHOP, $response['headers']['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('GET, POST, OPTIONS', $response['headers']['Access-Control-Allow-Methods'] ?? null);
        $this->assertSame('Accept, Authorization, Content-Type, X-Requested-With', $response['headers']['Access-Control-Allow-Headers'] ?? null);
        $this->assertSame('600', (string) ($response['headers']['Access-Control-Max-Age'] ?? ''));
        $this->assertStringContainsString('Origin', $response['headers']['Vary'] ?? '');
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response['headers']);
        $this->assertFalse($response['core_cors']);
    }

    public function test_answers_a_preflight_on_the_chat_routes(): void
    {
        $response = $this->serve('OPTIONS', '/escalated/v1/widget/chat/start', [
            'Origin' => self::SHOP,
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame(204, $response['status']);
        $this->assertSame(self::SHOP, $response['headers']['Access-Control-Allow-Origin'] ?? null);
    }

    public function test_gives_no_cors_grant_to_a_site_that_is_not_allowed(): void
    {
        $preflight = $this->serve('OPTIONS', '/escalated/v1/widget/tickets', [
            'Origin' => self::EVIL,
            'Access-Control-Request-Method' => 'POST',
        ]);
        $config = $this->serve('GET', '/escalated/v1/widget/config', ['Origin' => self::EVIL]);

        $this->assertSame(204, $preflight['status']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $preflight['headers']);
        $this->assertFalse($preflight['core_cors'], 'WordPress core must not grant CORS on widget routes');

        $this->assertSame(200, $config['status']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $config['headers']);
        $this->assertFalse($config['core_cors'], 'WordPress core must not grant CORS on widget routes');
    }

    public function test_leaves_core_cors_alone_outside_the_widget_routes(): void
    {
        $response = $this->serve('GET', '/escalated/v1/tickets', ['Origin' => self::SHOP]);

        $this->assertTrue($response['core_cors']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response['headers']);
    }

    public function test_lets_an_allowed_site_create_a_ticket(): void
    {
        $response = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], $this->ticket_payload());

        $this->assertSame(201, $response['status']);
        $this->assertSame(self::SHOP, $response['headers']['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('Retry-After', $response['headers']['Access-Control-Expose-Headers'] ?? null);
        $this->assertStringContainsString('Origin', $response['headers']['Vary'] ?? '');
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response['headers']);
        $this->assertNotNull(Ticket::find_by_reference($response['json']['reference']));
    }

    public function test_same_origin_and_originless_requests_are_unchanged(): void
    {
        foreach ([[], ['Origin' => 'http://example.org']] as $headers) {
            $response = $this->serve('GET', '/escalated/v1/widget/config', $headers);

            $this->assertSame(200, $response['status']);
            $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response['headers']);
        }
    }

    public function test_reads_allowed_sites_from_the_admin_setting_including_wildcards(): void
    {
        remove_filter('escalated_widget_allowed_origins', [$this, 'shop_origin']);
        Setting::set('widget_allowed_origins', "https://*.brand.test\nhttp://localhost:5173");

        $allowed = function (string $origin): ?string {
            return $this->serve('GET', '/escalated/v1/widget/config', ['Origin' => $origin])['headers']['Access-Control-Allow-Origin'] ?? null;
        };

        $this->assertSame('https://help.brand.test', $allowed('https://help.brand.test'));
        $this->assertSame('https://a.b.brand.test', $allowed('https://a.b.brand.test'));
        $this->assertSame('http://localhost:5173', $allowed('http://localhost:5173'));
        $this->assertNull($allowed('https://brand.test'));
        $this->assertNull($allowed('http://help.brand.test'));
        $this->assertNull($allowed('https://evilbrand.test'));
        $this->assertNull($allowed('http://localhost:5174'));
        $this->assertNull($allowed(self::SHOP));
    }

    public function test_parses_a_comma_separated_config_list(): void
    {
        $this->assertTrue(\Escalated\Services\WidgetOrigins::allows(self::SHOP));
        $this->assertSame(
            ['https://a.example.com', 'https://*.b.example.com'],
            \Escalated\Services\WidgetOrigins::parse_list(' https://a.example.com, https://*.b.example.com ,bogus')
        );
    }

    public function test_adds_cors_headers_to_error_answers_for_allowed_sites(): void
    {
        $invalid = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], ['name' => 'x']);
        $this->assertSame(400, $invalid['status']);
        $this->assertSame(self::SHOP, $invalid['headers']['Access-Control-Allow-Origin'] ?? null);

        $missing = $this->serve('GET', '/escalated/v1/widget/tickets/ESC-99999', ['Origin' => self::SHOP]);
        $this->assertSame(404, $missing['status']);
        $this->assertSame(self::SHOP, $missing['headers']['Access-Control-Allow-Origin'] ?? null);

        Setting::set('widget_enabled', '0');
        $disabled = $this->serve('GET', '/escalated/v1/widget/config', ['Origin' => self::SHOP]);
        $this->assertSame(403, $disabled['status']);
        $this->assertSame(self::SHOP, $disabled['headers']['Access-Control-Allow-Origin'] ?? null);
    }

    public function test_adds_cors_headers_to_rate_limit_answers_for_allowed_sites(): void
    {
        Setting::set('guest_rate_limit_tickets_per_minute', '1');

        $first = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], $this->ticket_payload());
        $limited = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], $this->ticket_payload());

        $this->assertSame(201, $first['status']);
        $this->assertSame(429, $limited['status']);
        $this->assertSame(self::SHOP, $limited['headers']['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('Retry-After', $limited['headers']['Access-Control-Expose-Headers'] ?? null);
        $this->assertArrayHasKey('Retry-After', $limited['headers']);
    }

    // =========================================================================
    // Guest forcing
    // =========================================================================

    public function test_treats_an_embedded_visitor_as_a_guest_even_when_signed_in(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $seen = [];
        $capture = function () use (&$seen) {
            $seen[] = get_current_user_id();
        };
        add_action('escalated_ticket_created', $capture);

        wp_set_current_user($admin);
        $this->serve('POST', '/escalated/v1/widget/tickets', [], $this->ticket_payload(['subject' => 'Same site']));
        wp_set_current_user($admin);
        $embedded = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], $this->ticket_payload(['subject' => 'Embedded']));

        remove_action('escalated_ticket_created', $capture);

        $this->assertSame(201, $embedded['status']);
        $this->assertSame([$admin, 0], $seen);
    }

    // =========================================================================
    // Config and host parameters
    // =========================================================================

    public function test_tells_the_widget_it_accepts_host_parameters(): void
    {
        $response = $this->serve('GET', '/escalated/v1/widget/config');

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertTrue($response['json']['accepts_metadata']);
        $this->assertArrayHasKey('color', $response['json']);
        $this->assertArrayHasKey('position', $response['json']);
        $this->assertArrayHasKey('greeting', $response['json']);
    }

    public function test_stores_host_parameters_sent_with_a_widget_ticket(): void
    {
        $response = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], $this->ticket_payload([
            'metadata' => [
                'order_number' => 'A-1001',
                'plan' => 'pro',
                'items' => 3,
                'vip' => true,
                'page_url' => 'https://shop.example.com/orders/A-1001',
                'embedded_on' => 'https://spoofed.example',
                'nested' => ['not' => 'kept'],
                'nothing' => null,
                'bad key!' => 'dropped',
                'note' => '  '.str_repeat('a', 600),
            ],
        ]));

        $this->assertSame(201, $response['status']);
        $metadata = $this->ticket_metadata($response['json']['reference']);

        $this->assertSame('A-1001', $metadata['order_number']);
        $this->assertSame('pro', $metadata['plan']);
        $this->assertSame(3, $metadata['items']);
        $this->assertTrue($metadata['vip']);
        $this->assertSame('https://shop.example.com/orders/A-1001', $metadata['page_url']);
        $this->assertSame(self::SHOP, $metadata['embedded_on']);
        $this->assertSame(500, mb_strlen($metadata['note']));
        $this->assertArrayNotHasKey('nested', $metadata);
        $this->assertArrayNotHasKey('nothing', $metadata);
        $this->assertArrayNotHasKey('bad key!', $metadata);
    }

    public function test_drops_a_page_url_that_is_not_a_web_address_and_a_spoofed_embedded_on(): void
    {
        $response = $this->serve('POST', '/escalated/v1/widget/tickets', [], $this->ticket_payload([
            'metadata' => ['page_url' => 'javascript:alert(1)', 'plan' => 'pro', 'embedded_on' => 'https://spoofed.example'],
        ]));

        $this->assertSame(201, $response['status']);
        $this->assertSame(['plan' => 'pro'], $this->ticket_metadata($response['json']['reference']));
    }

    public function test_keeps_at_most_25_entries(): void
    {
        $metadata = [];
        for ($i = 1; $i <= 40; $i++) {
            $metadata['key_'.$i] = $i;
        }

        $response = $this->serve('POST', '/escalated/v1/widget/tickets', [], $this->ticket_payload(['metadata' => $metadata]));

        $this->assertSame(201, $response['status']);
        $this->assertCount(25, $this->ticket_metadata($response['json']['reference']));
    }

    public function test_rejects_metadata_that_is_not_an_object_or_too_large(): void
    {
        $too_many = [];
        for ($i = 1; $i <= 51; $i++) {
            $too_many['key_'.$i] = $i;
        }

        $wpdb = \Escalated\Escalated::db();
        $tickets = \Escalated\Escalated::table('tickets');
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tickets}");

        foreach (['a string', ['a', 'list'], $too_many] as $metadata) {
            $response = $this->serve('POST', '/escalated/v1/widget/tickets', ['Origin' => self::SHOP], $this->ticket_payload(['metadata' => $metadata]));

            $this->assertSame(400, $response['status']);
            $this->assertSame(self::SHOP, $response['headers']['Access-Control-Allow-Origin'] ?? null);
        }

        $this->assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tickets}"));
    }

    public function test_leaves_widget_tickets_without_host_parameters_unchanged(): void
    {
        $response = $this->serve('POST', '/escalated/v1/widget/tickets', [], $this->ticket_payload());

        $this->assertSame(201, $response['status']);
        $this->assertNull($this->ticket_metadata($response['json']['reference']));
    }

    public function test_lets_an_allowed_site_start_a_chat_and_keeps_its_host_parameters(): void
    {
        $response = $this->serve('POST', '/escalated/v1/widget/chat/start', ['Origin' => self::SHOP], [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'Hello',
        ] + escalated_test_guest_proof('john@example.com', 'chat') + [
            'metadata' => ['page_url' => 'https://shop.example.com/cart', 'cart_total' => 129.5, 'deep' => ['x' => 1]],
        ]);

        $this->assertSame(201, $response['status']);
        $this->assertSame(self::SHOP, $response['headers']['Access-Control-Allow-Origin'] ?? null);

        $session = ChatSession::find(escalated_test_chat_id($response['json']));
        $this->assertSame([
            'page_url' => 'https://shop.example.com/cart',
            'cart_total' => 129.5,
            'embedded_on' => self::SHOP,
        ], json_decode((string) $session->metadata, true));

        $ticket = Ticket::find((int) $session->ticket_id);
        $this->assertSame('chat', $ticket->channel);
    }

    // =========================================================================
    // embed.js
    // =========================================================================

    public function test_serves_the_standalone_widget_script_for_other_sites_to_load(): void
    {
        $response = $this->serve('GET', '/escalated/v1/widget/embed.js', ['Origin' => self::EVIL]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/javascript; charset=utf-8', $response['headers']['Content-Type'] ?? null);
        $this->assertSame('public, max-age=3600', $response['headers']['Cache-Control'] ?? null);
        $this->assertSame('nosniff', $response['headers']['X-Content-Type-Options'] ?? null);
        $this->assertSame('cross-origin', $response['headers']['Cross-Origin-Resource-Policy'] ?? null);
        $this->assertSame('*', $response['headers']['Access-Control-Allow-Origin'] ?? null);
        $this->assertFalse($response['core_cors']);
        $this->assertSame(file_get_contents(ESCALATED_PLUGIN_DIR.'assets/widget/escalated-widget.js'), $response['body']);

        $etag = $response['headers']['ETag'] ?? '';
        $this->assertNotSame('', $etag);

        $cached = $this->serve('GET', '/escalated/v1/widget/embed.js', ['If-None-Match' => $etag]);
        $this->assertSame(304, $cached['status']);
        $this->assertSame('', $cached['body']);
        $this->assertSame($etag, $cached['headers']['ETag'] ?? null);
    }

    public function test_serves_the_script_while_the_widget_is_disabled(): void
    {
        Setting::set('widget_enabled', '0');

        $this->assertSame(200, $this->serve('GET', '/escalated/v1/widget/embed.js')['status']);
    }

    // =========================================================================
    // Admin settings
    // =========================================================================

    public function test_lets_admins_turn_the_widget_on_and_list_embedding_sites(): void
    {
        Setting::set('widget_enabled', '0');
        $settings = new Admin_Settings;

        $errors = $settings->persist([
            'widget_enabled' => '1',
            'widget_color' => '#112233',
            'widget_position' => 'bottom-left',
            'widget_greeting' => 'Hello there',
            'widget_allowed_origins' => "https://shop.example.com\r\nhttps://*.brand.test",
        ]);

        $this->assertSame([], $errors);
        $this->assertTrue(Setting::get_bool('widget_enabled'));
        $this->assertSame('#112233', Setting::get('widget_color'));
        $this->assertSame('bottom-left', Setting::get('widget_position'));
        $this->assertSame('Hello there', Setting::get('widget_greeting'));
        $this->assertSame("https://shop.example.com\nhttps://*.brand.test", Setting::get('widget_allowed_origins'));

        $errors = $settings->persist([
            'widget_enabled' => '1',
            'widget_allowed_origins' => "https://shop.example.com/checkout\n*",
        ]);

        $this->assertArrayHasKey('widget_allowed_origins', $errors);
        $this->assertSame("https://shop.example.com\nhttps://*.brand.test", Setting::get('widget_allowed_origins'));
    }
}
