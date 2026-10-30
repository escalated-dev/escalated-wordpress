<?php

/**
 * Two-factor enforcement (ADR 2026-10-29-configurable-access-features, port
 * checklist "Two-factor"). On WordPress the challenge sits between the
 * WordPress login and the Escalated screens, and the pass is kept in the
 * WordPress login session.
 */

use Escalated\Admin\Admin_Two_Factor;
use Escalated\Cli\TwoFactorCommand;
use Escalated\Models\Setting;
use Escalated\Models\TwoFactor;
use Escalated\Services\TwoFactorService;
use Escalated\Support\AccessFeatures;
use Escalated\Support\TwoFactorGate;

class Test_Two_Factor_Enforcement extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        \Escalated\Activator::activate();
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        $_POST = [];
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        parent::tear_down();
    }

    private function on(): void
    {
        add_filter('escalated_access_two_factor', '__return_true');
    }

    /** Sign in: a new WordPress login session for the user. */
    private function login(int $user_id): string
    {
        $expiration = time() + DAY_IN_SECONDS;
        $token = WP_Session_Tokens::get_instance($user_id)->create($expiration);
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);
        wp_set_current_user($user_id);

        return $token;
    }

    /** Enrol the user (confirmed) and return the secret and recovery codes. */
    private function enrol(int $user_id): array
    {
        $service = new TwoFactorService;
        $secret = $service->generate_secret();
        $codes = $service->generate_recovery_codes();
        $id = TwoFactor::create(['user_id' => $user_id, 'secret' => $secret, 'recovery_codes' => $codes]);
        TwoFactor::confirm($id);

        return [$secret, $codes];
    }

    private function code(string $secret, int $offset = 0): string
    {
        return (new TwoFactorService)->generate_totp($secret, (int) floor(time() / 30) + $offset);
    }

    /** Where an Escalated wp-admin screen sends the user, or null to let them in. */
    private function visit(string $page): ?string
    {
        $_GET = ['page' => $page];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'example.org';
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page='.$page;
        add_filter('wp_redirect', static function ($location) {
            throw new RuntimeException('redirect:'.$location);
        });
        try {
            TwoFactorGate::guard_admin();

            return null;
        } catch (RuntimeException $e) {
            return substr($e->getMessage(), strlen('redirect:'));
        } finally {
            remove_all_filters('wp_redirect');
            $_GET = [];
        }
    }

    private function rest_as_cookie(string $method, string $route): WP_REST_Response
    {
        return $this->server->dispatch(new WP_REST_Request($method, '/escalated/v1/'.$route));
    }

    // ---------------------------------------------------------------- switch

    public function test_switch_off_nobody_is_challenged_and_the_screens_answer_404(): void
    {
        $this->assertFalse(AccessFeatures::two_factor());
        Setting::set('two_factor', '1');
        $this->assertFalse(AccessFeatures::two_factor(), 'a setting does not flip the switch');

        $admin = $this->factory->user->create(['role' => 'administrator']);
        $this->enrol($admin);
        $this->login($admin);

        $this->assertNull($this->visit('escalated'), 'enrolled user challenged with the switch off');
        $this->assertSame(200, $this->rest_as_cookie('GET', 'admin/kb/articles')->get_status());
        $this->assertFalse(AccessFeatures::shared()['two_factor']);

        foreach (['render', 'render_challenge'] as $screen) {
            try {
                (new Admin_Two_Factor)->$screen();
                $this->fail($screen.' rendered with the switch off');
            } catch (WPDieException $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
        $_POST = ['escalated_two_factor_action' => 'setup'];
        try {
            (new Admin_Two_Factor)->handle_actions();
            $this->fail('setup ran with the switch off');
        } catch (WPDieException $e) {
            $this->assertSame(404, $e->getCode());
        }

        $token = \Escalated\Models\ApiToken::create_token($admin, '2fa', ['*'])['token'];
        $request = new WP_REST_Request('GET', '/escalated/v1/admin/two-factor');
        $request->set_header('Authorization', 'Bearer '.$token);
        $this->assertSame(404, $this->server->dispatch($request)->get_status());

        // The enrolment is kept for when the switch is turned on.
        $this->assertNotNull(TwoFactor::confirmed_for_user($admin));
    }

    // ------------------------------------------------------------- challenge

    public function test_enrolled_staff_are_sent_to_the_challenge_from_agent_and_admin_screens(): void
    {
        $this->on();
        $this->assertTrue(AccessFeatures::shared()['two_factor']);
        $admin = $this->factory->user->create(['role' => 'administrator']);
        [$secret] = $this->enrol($admin);
        $this->login($admin);

        $challenge = admin_url('admin.php?page='.TwoFactorGate::CHALLENGE_PAGE);
        $this->assertSame($challenge, $this->visit('escalated'), 'ticket list');
        $this->assertSame($challenge, $this->visit('escalated-settings'), 'settings');

        // JSON clients get 403 two_factor_required.
        $response = $this->rest_as_cookie('GET', 'admin/kb/articles');
        $this->assertSame(403, $response->get_status());
        $this->assertTrue($response->get_data()['data']['two_factor_required']);

        // The challenge page and its form.
        $_GET = ['page' => TwoFactorGate::CHALLENGE_PAGE];
        ob_start();
        (new Admin_Two_Factor)->render_challenge();
        $html = ob_get_clean();
        $this->assertStringContainsString('action="'.esc_url($challenge).'"', $html);
        $this->assertStringContainsString('name="recovery_code"', $html);

        // A wrong code is an error on code; a valid one returns to the page
        // asked for and lets the user in.
        $this->assertStringContainsString('error=code', Admin_Two_Factor::verify($admin, ['code' => '000000']));
        $this->assertSame('http://example.org/wp-admin/admin.php?page=escalated-settings', Admin_Two_Factor::verify($admin, ['code' => $this->code($secret)]));
        $this->assertNull($this->visit('escalated'));
        $this->assertSame(200, $this->rest_as_cookie('GET', 'admin/kb/articles')->get_status());

        // The same code (same time step) is refused in a new session.
        $this->login($admin);
        $this->assertStringContainsString('error=code', Admin_Two_Factor::verify($admin, ['code' => $this->code($secret)]));
    }

    public function test_a_new_login_is_challenged_again_and_a_pass_is_one_users(): void
    {
        $this->on();
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        [$secret] = $this->enrol($agent);
        $this->login($agent);
        Admin_Two_Factor::verify($agent, ['code' => $this->code($secret)]);
        $this->assertNull($this->visit('escalated'));

        $this->login($agent);
        $this->assertNotNull($this->visit('escalated'), 'a new login was not challenged');

        $other = $this->factory->user->create(['role' => 'escalated_agent']);
        $this->enrol($other);
        wp_set_current_user($other);
        $this->assertFalse(TwoFactorGate::passed($other));
    }

    public function test_a_recovery_code_works_once(): void
    {
        $this->on();
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        [, $codes] = $this->enrol($agent);
        $this->login($agent);

        $this->assertStringNotContainsString('error=', Admin_Two_Factor::verify($agent, ['recovery_code' => strtolower($codes[0])]));
        $this->assertTrue(TwoFactorGate::passed($agent));

        $this->login($agent);
        $this->assertStringContainsString('error=recovery_code', Admin_Two_Factor::verify($agent, ['recovery_code' => $codes[0]]));
        $this->assertSame(7, TwoFactor::remaining_recovery_codes(TwoFactor::for_user($agent)));
    }

    public function test_the_challenge_is_throttled(): void
    {
        $this->on();
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        [$secret] = $this->enrol($agent);
        $this->login($agent);

        for ($i = 0; $i < TwoFactorGate::ATTEMPTS_PER_MINUTE; $i++) {
            $this->assertStringContainsString('error=code', Admin_Two_Factor::verify($agent, ['code' => '000000']));
        }
        $this->assertStringContainsString('error=throttled', Admin_Two_Factor::verify($agent, ['code' => $this->code($secret)]));
        $this->assertFalse(TwoFactorGate::passed($agent));
    }

    // ------------------------------------------------------------- enrolment

    public function test_an_agent_enrols_through_the_screen(): void
    {
        $this->on();
        $agent = $this->factory->user->create(['role' => 'escalated_agent', 'user_email' => 'agent@example.com']);
        $this->login($agent);

        $this->assertTrue(Admin_Two_Factor::apply('setup', $agent, [])['ok']);
        ob_start();
        (new Admin_Two_Factor)->render();
        $html = ob_get_clean();
        $this->assertStringContainsString('otpauth://totp/', $html);
        $this->assertSame(1, preg_match('/secret=([A-Z2-7]+)/', $html, $m));
        $secret = $m[1];
        $this->assertSame(8, substr_count($html, '<li><code>'));

        // Shown once.
        ob_start();
        (new Admin_Two_Factor)->render();
        $this->assertStringNotContainsString('otpauth://', ob_get_clean());

        // Stored sealed.
        $this->assertStringNotContainsString($secret, (string) TwoFactor::for_user($agent)->secret);

        $this->assertSame('code', Admin_Two_Factor::apply('confirm', $agent, ['code' => '000000'])['error']);
        $this->assertTrue(Admin_Two_Factor::apply('confirm', $agent, ['code' => $this->code($secret)])['ok']);
        $this->assertNotNull(TwoFactor::confirmed_for_user($agent));
        // Confirming passes the challenge for this session.
        $this->assertNull($this->visit('escalated'));

        $this->assertTrue(Admin_Two_Factor::apply('disable', $agent, [])['ok']);
        $this->assertNull(TwoFactor::for_user($agent));
    }

    public function test_required_two_factor_sends_unenrolled_staff_to_enrol(): void
    {
        $this->on();
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        $this->login($agent);
        $this->assertNull($this->visit('escalated'), 'not required: unenrolled staff pass');

        // An admin requires it (the enrolment screen posts only this setting).
        $admin = $this->factory->user->create(['role' => 'administrator']);
        $this->assertTrue(Admin_Two_Factor::apply('require', $admin, ['two_factor_required' => '1'])['ok']);
        $this->assertSame('forbidden', Admin_Two_Factor::apply('require', $agent, ['two_factor_required' => '0'])['error']);
        $this->assertTrue(TwoFactorGate::required());

        $enrol = admin_url('admin.php?page='.TwoFactorGate::ENROL_PAGE);
        $this->login($agent);
        $this->assertSame($enrol, $this->visit('escalated'));
        $this->assertSame($enrol, $this->visit('escalated-reports'));
        $this->assertNull($this->visit(TwoFactorGate::ENROL_PAGE), 'the enrolment screen itself');
        $this->assertSame(403, $this->rest_as_cookie('GET', 'admin/kb/articles')->get_status());

        // Customers are not sent to enrol.
        $customer = $this->factory->user->create(['role' => 'subscriber']);
        $this->login($customer);
        $this->assertNull($this->visit('escalated'));

        // The setting wins over the config default.
        add_filter('escalated_access_two_factor_required', '__return_true');
        Setting::set('two_factor_required', '0');
        $this->assertFalse(TwoFactorGate::required());
        Setting::set('two_factor_required', '');
        $this->assertTrue(TwoFactorGate::required(), 'config default when no setting is saved');
    }

    public function test_api_token_requests_are_not_challenged_and_reset_clears_an_enrolment(): void
    {
        $this->on();
        $agent = $this->factory->user->create(['role' => 'escalated_agent']);
        $this->enrol($agent);
        $token = \Escalated\Models\ApiToken::create_token($agent, 'cli', ['*'])['token'];
        $request = new WP_REST_Request('GET', '/escalated/v1/tickets');
        $request->set_header('Authorization', 'Bearer '.$token);
        $this->assertSame(200, $this->server->dispatch($request)->get_status());

        TwoFactorCommand::reset_user($agent);
        $this->assertNull(TwoFactor::for_user($agent));
    }

    public function test_switch_reads_the_environment(): void
    {
        putenv('ESCALATED_TWO_FACTOR=1');
        try {
            $this->assertTrue(AccessFeatures::two_factor());
        } finally {
            putenv('ESCALATED_TWO_FACTOR');
        }
        $this->assertFalse(AccessFeatures::two_factor());
    }
}
