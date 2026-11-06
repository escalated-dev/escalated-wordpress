<?php

/**
 * Embedding the admin is optional and off by default (ADR
 * 2026-11-05-embeddable-admin, port checklist "Default unchanged").
 *
 * tests/fixtures/embed/default-surface.json is a snapshot of everything the
 * plugin registers, taken from the default branch before embedding existed:
 * the wp-admin menu pages (title, capability, slug), every escalated/v1 REST
 * route with its methods, every hook callback the plugin adds (on boot, and
 * when the wp-admin menu is built), the wp-admin X-Frame-Options hook and the
 * keys of the `escalatedAdmin` object the screens read. With no embed
 * configuration the live plugin must match it exactly.
 *
 * Regenerate only on purpose: ESCALATED_WRITE_EMBED_SNAPSHOT=1 vendor/bin/phpunit --filter Test_Embed_Default_Unchanged
 */
class Test_Embed_Default_Unchanged extends WP_UnitTestCase
{
    private const FIXTURE = __DIR__.'/fixtures/embed/default-surface.json';

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        \Escalated\Activator::activate();
    }

    public function tear_down(): void
    {
        global $wp_rest_server, $menu, $submenu;
        $wp_rest_server = null;
        $menu = [];
        $submenu = [];
        $_GET = [];
        set_current_screen('front');
        parent::tear_down();
    }

    public function test_the_registered_surface_matches_the_pre_embed_snapshot(): void
    {
        $live = self::surface();

        if (getenv('ESCALATED_WRITE_EMBED_SNAPSHOT')) {
            if (! is_dir(dirname(self::FIXTURE))) {
                mkdir(dirname(self::FIXTURE), 0777, true);
            }
            file_put_contents(self::FIXTURE, wp_json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertFileExists(self::FIXTURE);
        $expected = json_decode((string) file_get_contents(self::FIXTURE), true);

        $this->assertSame($expected['menu'], $live['menu'], 'wp-admin menu pages changed');
        $this->assertSame($expected['rest'], $live['rest'], 'REST routes changed');
        $this->assertSame($expected['hooks'], $live['hooks'], 'hook callbacks changed');
        $this->assertSame($expected['frame_options'], $live['frame_options'], 'X-Frame-Options hook changed');
        $this->assertSame($expected['admin_script_keys'], $live['admin_script_keys'], 'escalatedAdmin keys changed');
    }

    /**
     * Everything the plugin registers, as plain data.
     *
     * @return array<string, mixed>
     */
    public static function surface(): array
    {
        global $menu, $submenu, $wp_rest_server, $wp_scripts;

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        set_current_screen('toplevel_page_escalated');
        $_GET = ['page' => 'escalated'];

        // The wp-admin menu, as WordPress builds it for an administrator.
        $menu = [];
        $submenu = [];
        $admin_menu = new \Escalated\Admin\Admin_Menu;
        $admin_menu->register();
        $admin_menu->add_menus();

        $menu_pages = [];
        foreach ($menu as $item) {
            if (($item[2] ?? '') === 'escalated') {
                $menu_pages[] = ['top', (string) $item[0], (string) $item[1], (string) $item[2]];
            }
        }
        foreach ($submenu['escalated'] ?? [] as $item) {
            $menu_pages[] = ['sub', (string) $item[0], (string) $item[1], (string) $item[2]];
        }

        // Every escalated/v1 REST route and its methods.
        $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
        $rest = [];
        foreach ($wp_rest_server->get_routes('escalated/v1') as $route => $handlers) {
            $methods = [];
            foreach ($handlers as $handler) {
                $methods = array_merge($methods, array_keys(array_filter((array) ($handler['methods'] ?? []))));
            }
            $methods = array_values(array_unique($methods));
            sort($methods);
            $rest[$route] = $methods;
        }
        ksort($rest);

        // Every hook callback that belongs to the plugin.
        $hooks = [];
        foreach ($GLOBALS['wp_filter'] as $hook => $wp_hook) {
            foreach ($wp_hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $entry) {
                    $id = self::callback_id($entry['function']);
                    if ($id !== null) {
                        // activate_/deactivate_ hooks carry the plugin's path, which depends on the checkout.
                        $hook = str_replace(ESCALATED_PLUGIN_BASENAME, '{plugin}', (string) $hook);
                        $hooks[] = $hook.' @'.$priority.' '.$id.' ('.$entry['accepted_args'].')';
                    }
                }
            }
        }
        sort($hooks);

        // The escalatedAdmin object the screens read.
        $wp_scripts = new WP_Scripts;
        $admin_menu->enqueue_assets('toplevel_page_escalated');
        $data = (string) wp_scripts()->get_data('escalated-admin', 'data');
        $keys = [];
        if (preg_match('/var escalatedAdmin = (\{.*\});/s', $data, $m)) {
            $keys = array_keys((array) json_decode($m[1], true));
        }

        return [
            'menu' => $menu_pages,
            'rest' => $rest,
            'hooks' => array_values(array_unique($hooks)),
            'frame_options' => has_action('admin_init', 'send_frame_options_header'),
            'admin_script_keys' => $keys,
        ];
    }

    /** A stable id for a plugin callback, or null for anything else. */
    private static function callback_id(mixed $function): ?string
    {
        if (is_array($function) && count($function) === 2) {
            $class = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];

            return str_starts_with($class, 'Escalated\\') ? $class.'::'.$function[1] : null;
        }
        if (is_string($function)) {
            return str_starts_with($function, 'Escalated\\') || str_starts_with($function, 'escalated_') ? $function : null;
        }
        if ($function instanceof Closure) {
            $file = (string) (new ReflectionFunction($function))->getFileName();
            $root = wp_normalize_path(ESCALATED_PLUGIN_DIR);
            $file = wp_normalize_path($file);
            if (! str_starts_with($file, $root) || str_starts_with($file, $root.'tests/') || str_starts_with($file, $root.'vendor/')) {
                return null;
            }

            return 'Closure@'.substr($file, strlen($root));
        }

        return null;
    }
}
