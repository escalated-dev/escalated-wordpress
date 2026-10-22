<?php

/**
 * Stored secrets never reach the browser.
 *
 * Webhook signing secrets, the inbound mailbox password, API token hashes and
 * two-factor seeds are kept server-side so the plugin can use them. None of
 * them is shown back: every wp-admin screen and REST response that carries
 * one of these records is checked here for the raw secret anywhere in the
 * body. The one-time plaintext token on creation and the 2FA setup response
 * are the only places a secret is handed out, by design.
 */

use Escalated\Admin\Admin_Api_Tokens;
use Escalated\Admin\Admin_Settings;
use Escalated\Admin\Admin_Webhooks;
use Escalated\Models\ApiToken;
use Escalated\Models\Setting;
use Escalated\Models\TwoFactor;
use Escalated\Models\Webhook;
use Escalated\Models\WebhookDelivery;
use Escalated\Services\SecretMask;

class Test_Secrets_Never_Serialized extends WP_UnitTestCase
{
    private const WEBHOOK_SECRET = 'whsec-4c8e1a7b9d3f';

    private const IMAP_PASSWORD = 'imap-pass-8f7e6d5c4b';

    private int $admin_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        $admin = get_role('administrator');
        foreach (['escalated_webhook_manage', 'escalated_settings_manage', 'escalated_api_token_manage'] as $cap) {
            $admin->add_cap($cap);
        }

        add_filter('escalated_outbound_url_resolve', [$this, 'public_dns'], 10, 2);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter('escalated_outbound_url_resolve', [$this, 'public_dns'], 10);
        $_GET = [];
        wp_set_current_user(0);

        parent::tear_down();
    }

    public function public_dns($addresses, $host)
    {
        return ['93.184.216.34'];
    }

    // --- Webhooks ------------------------------------------------------------

    public function test_the_webhook_secret_is_not_on_the_list_edit_or_delivery_log_screens(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook_with_secret();
        WebhookDelivery::create($id, 'ticket.created', ['ok' => true]);

        $list = $this->render_webhooks([]);
        $this->assertStringContainsString('https://hooks.example.com/escalated', $list);
        $this->assert_no_secrets($list, [self::WEBHOOK_SECRET]);

        $edit = $this->render_webhooks(['action' => 'edit', 'id' => (string) $id]);
        $this->assert_no_secrets($edit, [self::WEBHOOK_SECRET]);
        // The form says a secret is set (the has_secret of the JSON ports)
        // without carrying it: an "Unchanged" placeholder and a remove box.
        $this->assertStringContainsString('name="clear_secret"', $edit);
        $this->assertMatchesRegularExpression('/id="webhook-secret"[^>]*placeholder="Unchanged"/s', $edit);
        $this->assertDoesNotMatchRegularExpression('/id="webhook-secret"[^>]*value=/s', $edit);

        $log = $this->render_webhooks(['action' => 'deliveries', 'id' => (string) $id]);
        $this->assertStringContainsString('ticket.created', $log);
        $this->assert_no_secrets($log, [self::WEBHOOK_SECRET]);
    }

    public function test_the_edit_screen_offers_no_remove_box_when_no_secret_is_stored(): void
    {
        wp_set_current_user($this->admin_id);
        $id = (int) Webhook::create([
            'url' => 'https://hooks.example.com/plain',
            'events' => ['ticket.created'],
            'secret' => '',
            'active' => 1,
        ]);

        $edit = $this->render_webhooks(['action' => 'edit', 'id' => (string) $id]);

        $this->assertStringNotContainsString('name="clear_secret"', $edit);
    }

    public function test_saving_a_webhook_with_the_secret_left_blank_keeps_the_stored_one(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook_with_secret();

        $this->update_webhook($id, ['secret' => '', 'events' => ['ticket.created', 'ticket.updated']]);

        $this->assertSame(self::WEBHOOK_SECRET, Webhook::find($id)->secret);
        $this->assertSame(['ticket.created', 'ticket.updated'], Webhook::subscribed_events(Webhook::find($id)));

        // An explicit "do not clear" is the same as leaving the box unticked.
        foreach (['0', 'false', ''] as $no) {
            $this->update_webhook($id, ['secret' => '', 'clear_secret' => $no]);
            $this->assertSame(self::WEBHOOK_SECRET, Webhook::find($id)->secret, "clear_secret={$no}");
        }
    }

    public function test_a_new_webhook_secret_replaces_the_stored_one_and_clear_secret_removes_it(): void
    {
        wp_set_current_user($this->admin_id);
        $id = $this->webhook_with_secret();

        $this->update_webhook($id, ['secret' => 'whsec-rotated']);
        $this->assertSame('whsec-rotated', Webhook::find($id)->secret);

        $this->update_webhook($id, ['secret' => '', 'clear_secret' => '1']);
        $this->assertSame('', (string) Webhook::find($id)->secret);
    }

    // --- General settings (inbound mailbox password) -------------------------

    public function test_the_settings_screen_shows_the_inbound_password_only_as_a_full_mask(): void
    {
        wp_set_current_user($this->admin_id);
        Setting::set('inbound_email_password', self::IMAP_PASSWORD);

        $html = $this->render_settings();

        $this->assert_no_secrets($html, [self::IMAP_PASSWORD, substr(self::IMAP_PASSWORD, 0, 3).'*']);
        $this->assertMatchesRegularExpression(
            '/name="inbound_email_password"[^>]*value="\*{8}"/s',
            $html
        );
    }

    public function test_the_settings_screen_leaves_the_password_field_empty_when_none_is_stored(): void
    {
        wp_set_current_user($this->admin_id);
        Setting::set('inbound_email_password', '');

        $this->assertMatchesRegularExpression(
            '/name="inbound_email_password"[^>]*value=""/s',
            $this->render_settings()
        );
    }

    public function test_posting_the_mask_back_keeps_the_stored_inbound_password(): void
    {
        wp_set_current_user($this->admin_id);
        Setting::set('inbound_email_password', self::IMAP_PASSWORD);

        // The mask this screen now shows, and the older "abc*****" form.
        foreach (['********', 'ima*****'] as $mask) {
            (new Admin_Settings)->persist(['inbound_email_password' => $mask]);
            $this->assertSame(self::IMAP_PASSWORD, Setting::get('inbound_email_password'), $mask);
        }

        (new Admin_Settings)->persist(['inbound_email_password' => 'imap-pass-rotated']);
        $this->assertSame('imap-pass-rotated', Setting::get('inbound_email_password'));
    }

    public function test_the_secret_mask_helper(): void
    {
        $this->assertSame('********', SecretMask::mask('anything'));
        $this->assertSame('', SecretMask::mask(''));
        $this->assertSame('', SecretMask::mask(null));

        $this->assertTrue(SecretMask::is_masked('********'));
        $this->assertTrue(SecretMask::is_masked('abc*****'));
        $this->assertFalse(SecretMask::is_masked(''));
        $this->assertFalse(SecretMask::is_masked(null));
        $this->assertFalse(SecretMask::is_masked('abcd****'));
        $this->assertFalse(SecretMask::is_masked('real-secret'));
    }

    // --- API tokens ----------------------------------------------------------

    public function test_the_api_token_list_endpoint_never_shows_any_part_of_a_stored_token(): void
    {
        [$bearer, $hash] = $this->token_and_hash();
        $legacy = $this->legacy_plaintext_token();

        $response = $this->server->dispatch($this->rest('GET', '/escalated/v1/admin/api-tokens', $bearer));

        $this->assertSame(200, $response->get_status());
        $body = (string) wp_json_encode($response->get_data());
        $this->assert_no_secrets($body, [
            $hash, substr($hash, 0, 8), substr($hash, -8),
            $bearer, substr($bearer, 0, 8),
            $legacy, substr($legacy, 0, 8), substr($legacy, -8),
        ]);
        foreach ($response->get_data()['tokens'] as $row) {
            $this->assertArrayNotHasKey('token', $row);
            $this->assertSame('********', $row['token_masked']);
        }
    }

    public function test_creating_an_api_token_returns_the_plaintext_once_and_never_the_hash(): void
    {
        [$bearer] = $this->token_and_hash();

        $response = $this->server->dispatch($this->rest('POST', '/escalated/v1/admin/api-tokens', $bearer, [
            'name' => 'CI',
            'user_id' => $this->admin_id,
        ]));

        $this->assertSame(201, $response->get_status());
        $token = $response->get_data()['token'];
        $stored = ApiToken::find($token['id'])->token;
        $this->assertSame(hash('sha256', $token['plain_token']), $stored);
        $this->assert_no_secrets((string) wp_json_encode($response->get_data()), [$stored]);
    }

    public function test_the_api_tokens_screen_never_shows_any_part_of_a_stored_token(): void
    {
        wp_set_current_user($this->admin_id);
        [, $hash] = $this->token_and_hash();
        $legacy = $this->legacy_plaintext_token();

        ob_start();
        (new Admin_Api_Tokens)->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('legacy-token', $html);
        $this->assert_no_secrets($html, [
            $hash, substr($hash, 0, 8), substr($hash, -8),
            $legacy, substr($legacy, 0, 8), substr($legacy, -8),
        ]);
    }

    // --- Two-factor ----------------------------------------------------------

    public function test_the_two_factor_status_and_recovery_endpoints_never_return_the_stored_seed_or_hashes(): void
    {
        $user_id = $this->factory->user->create(['role' => 'escalated_agent']);
        $bearer = ApiToken::create_token($user_id, '2fa', ['*'])['token'];

        $setup = $this->server->dispatch($this->rest('POST', '/escalated/v1/admin/two-factor/setup', $bearer));
        $this->assertSame(201, $setup->get_status());
        $seed = $setup->get_data()['secret'];

        $record = TwoFactor::for_user($user_id);
        $stored = [(string) $record->secret, $seed];
        foreach ((array) json_decode((string) $record->recovery_codes, true) as $hash) {
            $stored[] = (string) $hash;
        }

        $pending = $this->server->dispatch($this->rest('GET', '/escalated/v1/admin/two-factor', $bearer));
        $this->assertTrue($pending->get_data()['pending']);
        $this->assert_no_secrets((string) wp_json_encode($pending->get_data()), $stored);

        $code = (new \Escalated\Services\TwoFactorService)->generate_totp($seed, (int) floor(time() / 30));
        $confirm = $this->server->dispatch($this->rest('POST', '/escalated/v1/admin/two-factor/confirm', $bearer, ['code' => $code]));
        $this->assertSame(200, $confirm->get_status());
        $this->assert_no_secrets((string) wp_json_encode($confirm->get_data()), $stored);

        $status = $this->server->dispatch($this->rest('GET', '/escalated/v1/admin/two-factor', $bearer));
        $this->assertTrue($status->get_data()['enabled']);
        $this->assert_no_secrets((string) wp_json_encode($status->get_data()), $stored);

        $regen = $this->server->dispatch($this->rest('POST', '/escalated/v1/admin/two-factor/recovery-codes', $bearer));
        $this->assertSame(200, $regen->get_status());
        $new_hashes = (array) json_decode((string) TwoFactor::for_user($user_id)->recovery_codes, true);
        $this->assertNotEmpty($new_hashes);
        $this->assert_no_secrets((string) wp_json_encode($regen->get_data()), array_merge($stored, $new_hashes));
    }

    // --- Helpers -------------------------------------------------------------

    private function assert_no_secrets(string $body, array $secrets): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
    }

    private function webhook_with_secret(): int
    {
        return (int) Webhook::create([
            'url' => 'https://hooks.example.com/escalated',
            'events' => ['ticket.created'],
            'secret' => self::WEBHOOK_SECRET,
            'active' => 1,
        ]);
    }

    private function update_webhook(int $id, array $fields): void
    {
        (new Admin_Webhooks)->process($fields + [
            'escalated_webhook_action' => 'update',
            'id' => (string) $id,
            '_escalated_nonce' => wp_create_nonce('escalated_webhook_update_'.$id),
            'url' => 'https://hooks.example.com/escalated',
            'events' => ['ticket.created'],
            'active' => '1',
        ]);
    }

    private function render_webhooks(array $query): string
    {
        $_GET = $query;
        ob_start();
        (new Admin_Webhooks)->render();

        return (string) ob_get_clean();
    }

    private function render_settings(): string
    {
        $_GET = [];
        ob_start();
        (new Admin_Settings)->render();

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: string, 1: string} the plaintext bearer and its stored hash
     */
    private function token_and_hash(): array
    {
        $created = ApiToken::create_token($this->admin_id, 'admin-runner', ['*']);

        return [$created['token'], (string) $created['record']->token];
    }

    /**
     * A token stored before tokens were hashed: the column holds the
     * plaintext until it is next used.
     */
    private function legacy_plaintext_token(): string
    {
        $plain = 'legacyplain'.bin2hex(random_bytes(16));
        ApiToken::create([
            'user_id' => $this->admin_id,
            'name' => 'legacy-token',
            'token' => $plain,
            'abilities' => ['*'],
        ]);

        return $plain;
    }

    private function rest(string $method, string $route, string $bearer, array $body = []): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        $request->set_header('Authorization', 'Bearer '.$bearer);
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return $request;
    }
}
