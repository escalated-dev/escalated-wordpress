<?php

/**
 * Embedding Escalated's admin in the host's own admin (ADR
 * 2026-11-05-embeddable-admin), as it applies to WordPress: the admin screens
 * already live in wp-admin, so embedding means (a) handing a custom admin the
 * menu as NavigationItem[] data and (b) letting allow-listed origins frame
 * the screens chrome-free (iframe mode). See docs/embedding-admin.md.
 */

use Escalated\Admin\Admin_Menu;
use Escalated\Support\EmbedAdmin;

class Test_Embed_Admin extends WP_UnitTestCase
{
    private int $admin;

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        \Escalated\Activator::activate();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        $GLOBALS['wp_scripts'] = new WP_Scripts;
    }

    public function tear_down(): void
    {
        global $menu, $submenu, $wp_rest_server;
        $menu = [];
        $submenu = [];
        $wp_rest_server = null;
        $_GET = [];
        foreach (['ESCALATED_EMBED', 'ESCALATED_EMBED_MODE', 'ESCALATED_EMBED_FRAME_ANCESTORS'] as $name) {
            putenv($name);
        }
        set_current_screen('front');
        parent::tear_down();
    }

    /** Turn embedding on the way a host does (the helper adds an escalated_ui_embed filter). */
    private function embed(array $options = []): void
    {
        escalated_embed_admin($options);
    }

    /** A wp-admin request for an Escalated screen, with the menu built as WordPress builds it. */
    private function visit(string $page, array $query = [], ?int $user = null): Admin_Menu
    {
        wp_set_current_user($user ?? $this->admin);
        set_current_screen('toplevel_page_escalated');
        $_GET = ['page' => $page] + $query;
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $admin_menu = new Admin_Menu;
        $admin_menu->add_menus();

        return $admin_menu;
    }

    /** The `escalatedAdmin` object the screens receive. */
    private function script_data(Admin_Menu $admin_menu): array
    {
        $admin_menu->enqueue_assets('toplevel_page_escalated');
        $data = (string) wp_scripts()->get_data('escalated-admin', 'data');
        $this->assertSame(1, preg_match('/var escalatedAdmin = (\{.*\});/s', $data, $m));

        return json_decode($m[1], true);
    }

    /** @return list<array{0: string, 1?: string}> */
    private function frame_headers(): array
    {
        $ops = [];
        EmbedAdmin::apply_frame_headers(
            static function (string $header, bool $replace = true) use (&$ops) {
                $ops[] = ['add', $header];
            },
            static function (string $name) use (&$ops) {
                $ops[] = ['remove', $name];
            }
        );

        return $ops;
    }

    // ------------------------------------------------------------- config

    public function test_everything_is_off_by_default(): void
    {
        $config = EmbedAdmin::config();

        $this->assertFalse($config['enabled']);
        $this->assertSame('layout', $config['mode']);
        $this->assertNull($config['layout']);
        $this->assertNull($config['guard']);
        $this->assertSame([], $config['frame_ancestors']);
        $this->assertFalse(EmbedAdmin::enabled());
    }

    public function test_default_responses_carry_no_embed_or_navigation_and_keep_x_frame_options(): void
    {
        $data = $this->script_data($this->visit('escalated', ['escalated_embed' => '1']));

        $this->assertArrayNotHasKey('embed', $data);
        $this->assertArrayNotHasKey('navigation', $data);
        $this->assertSame(10, has_action('admin_init', 'send_frame_options_header'));
        $this->assertSame([], $this->frame_headers());
        $this->assertStringNotContainsString('escalated_embed', admin_url('admin.php?page=escalated-tags'));
        $this->assertStringNotContainsString('escalated-embedded', (string) apply_filters('admin_body_class', ''));
    }

    public function test_environment_variables_configure_it_and_wildcard_origins_are_rejected(): void
    {
        putenv('ESCALATED_EMBED=true');
        putenv('ESCALATED_EMBED_MODE=iframe');
        putenv('ESCALATED_EMBED_FRAME_ANCESTORS=https://admin.example.com, *, https://*.example.com, ftp://files.example.com, https://other.example.com/path, http://localhost:8080/, https://admin.example.com');

        $config = EmbedAdmin::config();

        $this->assertTrue($config['enabled']);
        $this->assertSame('iframe', $config['mode']);
        $this->assertSame(['https://admin.example.com', 'http://localhost:8080'], $config['frame_ancestors']);
    }

    public function test_the_filter_and_the_helper_are_equivalent_and_an_unknown_mode_falls_back_to_layout(): void
    {
        add_filter('escalated_ui_embed', static fn (array $c) => ['enabled' => true, 'mode' => 'popup'] + $c);
        $this->assertTrue(EmbedAdmin::enabled());
        $this->assertSame('layout', EmbedAdmin::config()['mode']);

        remove_all_filters('escalated_ui_embed');
        $this->assertFalse(EmbedAdmin::enabled());

        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com'], 'layout' => 'custom-admin']);
        $config = EmbedAdmin::config();
        $this->assertTrue($config['enabled']);
        $this->assertSame('iframe', $config['mode']);
        $this->assertSame('custom-admin', $config['layout']);
        $this->assertSame(['https://admin.example.com'], $config['frame_ancestors']);
    }

    // ------------------------------------------------------------- the surface stays put

    public function test_menu_pages_capabilities_and_rest_routes_do_not_move_while_embedding_is_on(): void
    {
        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']]);
        $expected = json_decode((string) file_get_contents(__DIR__.'/fixtures/embed/default-surface.json'), true);

        $live = Test_Embed_Default_Unchanged::surface();

        // Same pages, same capabilities: WordPress's own capability check on
        // each page (Escalated's admin gate here) is untouched.
        $this->assertSame($expected['menu'], $live['menu']);
        $this->assertSame($expected['rest'], $live['rest']);
    }

    // ------------------------------------------------------------- props

    public function test_layout_mode_marks_escalated_screens_as_embedded_and_adds_no_headers(): void
    {
        $this->embed();
        $data = $this->script_data($this->visit('escalated-tags'));

        $this->assertSame([
            'enabled' => true,
            'mode' => 'layout',
            'layout' => null,
            'route' => 'escalated.admin.tags.index',
            'base_url' => '/wp-admin/admin.php',
            'allowed_origins' => [],
        ], $data['embed']);
        $this->assertIsArray($data['navigation']);
        $this->assertNotEmpty($data['navigation']);

        $this->assertSame(10, has_action('admin_init', 'send_frame_options_header'));
        $this->assertSame([], $this->frame_headers());
    }

    public function test_iframe_mode_without_the_frame_flag_is_the_normal_wp_admin_screen(): void
    {
        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']]);
        $data = $this->script_data($this->visit('escalated-tags'));

        $this->assertFalse($data['embed']['enabled']);
        $this->assertSame('iframe', $data['embed']['mode']);
        $this->assertSame(['https://admin.example.com'], $data['embed']['allowed_origins']);
        $this->assertSame(10, has_action('admin_init', 'send_frame_options_header'));
        $this->assertSame([], $this->frame_headers());
        $this->assertStringNotContainsString('escalated-embedded', (string) apply_filters('admin_body_class', ''));
    }

    // ------------------------------------------------------------- iframe responses

    public function test_iframe_mode_framed_screen_gets_frame_ancestors_and_loses_x_frame_options(): void
    {
        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com', 'https://ops.example.com']]);
        $data = $this->script_data($this->visit('escalated-tags', ['escalated_embed' => '1']));

        $this->assertTrue($data['embed']['enabled']);
        $this->assertSame('escalated.admin.tags.index', $data['embed']['route']);
        $this->assertFalse(has_action('admin_init', 'send_frame_options_header'));
        $this->assertSame([
            ['remove', 'X-Frame-Options'],
            ['add', "Content-Security-Policy: frame-ancestors 'self' https://admin.example.com https://ops.example.com"],
        ], $this->frame_headers());
    }

    public function test_iframe_mode_with_no_origins_allows_only_self(): void
    {
        $this->embed(['mode' => 'iframe']);
        $this->visit('escalated', ['escalated_embed' => '1']);

        $this->assertSame([
            ['remove', 'X-Frame-Options'],
            ['add', "Content-Security-Policy: frame-ancestors 'self'"],
        ], $this->frame_headers());
    }

    public function test_iframe_mode_leaves_other_wp_admin_screens_alone(): void
    {
        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']]);
        $this->visit('woocommerce', ['escalated_embed' => '1']);

        $this->assertSame(10, has_action('admin_init', 'send_frame_options_header'));
        $this->assertSame([], $this->frame_headers());
        $this->assertStringNotContainsString('escalated_embed', admin_url('admin.php?page=escalated-tags'));
    }

    public function test_iframe_mode_framed_screen_renders_without_wp_admin_chrome(): void
    {
        add_action('in_admin_header', 'wp_admin_bar_render', 0);
        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']]);
        $admin_menu = $this->visit('escalated-tags', ['escalated_embed' => '1']);

        $this->assertStringContainsString('escalated-embedded', (string) apply_filters('admin_body_class', 'folded'));
        $this->assertFalse(has_action('in_admin_header', 'wp_admin_bar_render'));

        ob_start();
        do_action('admin_head');
        $head = (string) ob_get_clean();
        foreach (['#wpadminbar', '#adminmenumain', '#wpfooter'] as $chrome) {
            $this->assertStringContainsString($chrome, $head);
        }

        // The frame bridge (ready / resize / navigate messages to an allowed parent only).
        $admin_menu->enqueue_assets('toplevel_page_escalated');
        $this->assertTrue(wp_script_is('escalated-embed-bridge', 'enqueued'));
        $this->assertFileExists(ESCALATED_PLUGIN_DIR.'assets/js/embed-bridge.js');
    }

    public function test_iframe_mode_links_and_redirects_keep_the_frame_flag(): void
    {
        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']]);
        $this->visit('escalated-tags', ['escalated_embed' => '1']);

        $this->assertStringContainsString('escalated_embed=1', admin_url('admin.php?page=escalated-departments&message=created'));
        $this->assertStringNotContainsString('escalated_embed', admin_url('options-general.php'));
        $this->assertStringNotContainsString('escalated_embed', admin_url('admin.php?page=woocommerce'));
    }

    // ------------------------------------------------------------- host guard

    public function test_the_host_guard_runs_first_on_embedded_screens_and_can_refuse(): void
    {
        $editor = self::factory()->user->create(['role' => 'escalated_admin']);
        $this->embed(['guard' => 'manage_options']);
        $this->visit('escalated-tags', [], $editor);

        $this->assertSame(0, has_action('admin_init', [EmbedAdmin::class, 'guard_request']));
        try {
            EmbedAdmin::guard_request();
            $this->fail('the host guard did not refuse');
        } catch (WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame([], escalated_admin_navigation($editor));

        // The administrator passes it.
        $this->visit('escalated-tags');
        EmbedAdmin::guard_request();
        $this->assertNotEmpty(escalated_admin_navigation($this->admin));
    }

    public function test_a_callable_guard_receives_the_user(): void
    {
        $seen = null;
        $this->embed(['guard' => static function (WP_User $user) use (&$seen) {
            $seen = $user->ID;

            return false;
        }]);
        $this->visit('escalated');

        try {
            EmbedAdmin::guard_request();
            $this->fail('the host guard did not refuse');
        } catch (WPDieException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame($this->admin, $seen);
    }

    public function test_the_guard_does_not_run_while_embedding_is_off(): void
    {
        add_filter('escalated_ui_embed', static fn (array $c) => ['guard' => '__return_false'] + $c);
        $this->visit('escalated');

        $this->assertFalse(has_action('admin_init', [EmbedAdmin::class, 'guard_request']));
    }

    // ------------------------------------------------------------- navigation

    public function test_navigation_items_have_the_adr_shape_order_and_active_state(): void
    {
        $this->embed();
        $_GET = ['page' => 'escalated-departments', 'action' => 'edit', 'id' => '3'];

        $items = escalated_admin_navigation($this->admin);

        $this->assertSame([
            'reports', 'tickets', 'departments', 'sla-policies', 'escalation-rules', 'workflows',
            'automations', 'webhooks', 'tags', 'canned-responses', 'macros', 'newsletters', 'skills',
            'users', 'business-hours', 'api-tokens', 'settings',
        ], array_column($items, 'key'));

        $departments = $items[2];
        $this->assertSame([
            'key' => 'departments',
            'label' => 'Departments',
            'route' => 'escalated.admin.departments.index',
            'url' => '/wp-admin/admin.php?page=escalated-departments',
            'icon' => 'departments',
            'position' => 30,
            'active' => true,
            'badge' => null,
            'plugin' => null,
            'children' => [],
        ], $departments);
        $this->assertSame(['departments'], array_column(array_filter($items, static fn ($i) => $i['active']), 'key'));
        $this->assertSame(53.5, $items[6]['position']);

        $positions = array_column($items, 'position');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_navigation_works_while_embedding_is_off_and_in_iframe_mode_links_carry_the_frame_flag(): void
    {
        $this->assertNotEmpty(escalated_admin_navigation($this->admin), 'the helper works with embedding off');

        $this->embed(['mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']]);
        $items = escalated_admin_navigation($this->admin);
        $this->assertSame('/wp-admin/admin.php?page=escalated-reports&escalated_embed=1', $items[0]['url']);
    }

    public function test_roles_appear_only_with_roles_and_permissions_on_and_missing_capabilities_drop_items(): void
    {
        $this->embed();
        $this->assertNotContains('roles', array_column(escalated_admin_navigation($this->admin), 'key'));

        add_filter('escalated_access_roles_permissions', '__return_true');
        $keys = array_column(escalated_admin_navigation($this->admin), 'key');
        $this->assertContains('roles', $keys);
        $this->assertContains('settings', $keys);

        // A staff user without settings.view loses Settings.
        $manager = self::factory()->user->create(['role' => 'escalated_admin']);
        $user = new WP_User($manager);
        $user->add_cap('escalated_settings_view', false);
        $keys = array_column(escalated_admin_navigation($manager), 'key');
        $this->assertNotContains('settings', $keys);
        $this->assertContains('tickets', $keys);

        // Someone who may not open any Escalated screen gets nothing.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        $this->assertSame([], escalated_admin_navigation($subscriber));
        $this->assertSame([], escalated_admin_navigation(0));
    }

    public function test_add_on_menu_items_are_merged_filtered_by_capability_and_sorted(): void
    {
        $this->embed();
        add_filter('escalated_admin_navigation_items', static function (array $items) {
            $items[] = ['plugin' => 'billing', 'label' => 'Billing', 'url' => '/wp-admin/admin.php?page=billing', 'icon' => 'card', 'position' => 55, 'badge' => 3, 'capability' => 'manage_options',
                'children' => [['label' => 'Invoices', 'url' => '/wp-admin/admin.php?page=billing-invoices']]];
            $items[] = ['plugin' => 'billing', 'label' => 'Secret', 'url' => '/wp-admin/admin.php?page=secret', 'position' => 56, 'capability' => 'do_not_have'];

            return $items;
        });
        $_GET = ['page' => 'billing-invoices'];

        $items = escalated_admin_navigation($this->admin);
        $keys = array_column($items, 'key');

        $this->assertNotContains('plugin:billing:1', $keys);
        $index = array_search('plugin:billing:0', $keys, true);
        $this->assertSame('webhooks', $keys[$index - 1]);
        $this->assertSame('tags', $keys[$index + 1]);
        $this->assertSame([
            'key' => 'plugin:billing:0',
            'label' => 'Billing',
            'route' => null,
            'url' => '/wp-admin/admin.php?page=billing',
            'icon' => 'card',
            'position' => 55,
            'active' => false,
            'badge' => 3,
            'plugin' => 'billing',
            'children' => [[
                'key' => 'plugin:billing:0:0',
                'label' => 'Invoices',
                'route' => null,
                'url' => '/wp-admin/admin.php?page=billing-invoices',
                'icon' => null,
                'position' => 0,
                'active' => true,
                'badge' => null,
                'plugin' => 'billing',
            ]],
        ], $items[$index]);
    }

    public function test_the_documented_host_menu_template_renders_the_navigation(): void
    {
        $this->embed();
        wp_set_current_user($this->admin);
        $_GET = ['page' => 'escalated-tags'];

        ob_start();
        include __DIR__.'/fixtures/embed/host-support-menu.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<nav class="host-support-menu"', $html);
        $this->assertStringContainsString('href="/wp-admin/admin.php?page=escalated-reports"', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#page=escalated-tags"\s+aria-current="page">\s*Tags#', $html);

        // Nothing for someone who may not open the admin.
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        ob_start();
        include __DIR__.'/fixtures/embed/host-support-menu.php';
        $this->assertSame('', trim((string) ob_get_clean()));
    }
}
