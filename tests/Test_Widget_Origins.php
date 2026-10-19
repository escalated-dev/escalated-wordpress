<?php

/**
 * Unit tests for the widget embedding allow list (origin normalisation and matching).
 */

use Escalated\Models\Setting;
use Escalated\Services\WidgetOrigins;

class Test_Widget_Origins extends WP_UnitTestCase
{
    /** @var list<string> */
    private array $configured = [];

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        Setting::set('widget_allowed_origins', '');

        $this->configured = [];
        add_filter('escalated_widget_allowed_origins', [$this, 'configured_origins']);
    }

    public function tear_down(): void
    {
        remove_filter('escalated_widget_allowed_origins', [$this, 'configured_origins']);
        parent::tear_down();
    }

    public function configured_origins(array $origins): array
    {
        return array_merge($origins, $this->configured);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function patterns(): array
    {
        return [
            'mixed case' => ['https://Shop.Example.com', 'https://shop.example.com'],
            'trailing slash' => ['https://shop.example.com/', 'https://shop.example.com'],
            'default https port' => ['https://shop.example.com:443', 'https://shop.example.com'],
            'custom port' => ['http://localhost:5173', 'http://localhost:5173'],
            'wildcard' => ['https://*.example.com', 'https://*.example.com'],
            'wildcard with port' => ['https://*.example.com:8443', 'https://*.example.com:8443'],
            'path' => ['https://shop.example.com/checkout', null],
            'query' => ['https://shop.example.com?x=1', null],
            'no scheme' => ['shop.example.com', null],
            'bare star' => ['*', null],
            'scheme and star' => ['https://*', null],
            'inner star' => ['https://a.*.example.com', null],
            'ftp' => ['ftp://shop.example.com', null],
            'userinfo' => ['https://user@shop.example.com', null],
        ];
    }

    /**
     * @dataProvider patterns
     */
    public function test_normalises_admin_entered_origins(string $entry, ?string $expected): void
    {
        $this->assertSame($expected, WidgetOrigins::normalize_pattern($entry));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function origins(): array
    {
        return [
            'exact' => ['https://shop.example.com', true],
            'case and default port' => ['https://SHOP.example.com:443', true],
            'other scheme' => ['http://shop.example.com', false],
            'other port' => ['https://shop.example.com:8443', false],
            'subdomain' => ['https://help.brand.test', true],
            'deep subdomain' => ['https://a.b.brand.test', true],
            'apex' => ['https://brand.test', false],
            'suffix lookalike' => ['https://notbrand.test', false],
            'suffix attack' => ['https://help.brand.test.evil.com', false],
            'localhost port' => ['http://localhost:5173', true],
            'localhost no port' => ['http://localhost', false],
            'null origin' => ['null', false],
        ];
    }

    /**
     * @dataProvider origins
     */
    public function test_matches_exact_origins_ports_and_subdomain_wildcards(string $origin, bool $allowed): void
    {
        $this->configured = ['https://shop.example.com', 'https://*.brand.test', 'http://localhost:5173'];

        $this->assertSame($allowed, WidgetOrigins::allows($origin));
    }

    public function test_merges_the_config_list_with_the_admin_setting(): void
    {
        $this->configured = ['https://shop.example.com'];
        Setting::set('widget_allowed_origins', "https://blog.example.com, https://bad.example.com/path\n");

        $this->assertSame(['https://shop.example.com', 'https://blog.example.com'], WidgetOrigins::configured());
    }

    public function test_reports_invalid_entries(): void
    {
        $this->assertSame([], WidgetOrigins::invalid_entries("https://shop.example.com\n https://*.brand.test ,"));
        $this->assertSame(['https://shop.example.com/checkout', '*'], WidgetOrigins::invalid_entries("https://shop.example.com/checkout\n*"));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function request_origins(): array
    {
        return [
            'no header' => [null, null],
            'null' => ['null', null],
            'same origin' => ['http://example.org', null],
            'same origin default port' => ['http://example.org:80', null],
            'other site' => ['https://shop.example.com', 'https://shop.example.com'],
            'same host other scheme' => ['https://example.org', 'https://example.org'],
        ];
    }

    /**
     * @dataProvider request_origins
     */
    public function test_only_reports_an_origin_that_names_another_site(?string $origin, ?string $expected): void
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/config');
        if ($origin !== null) {
            $request->set_header('Origin', $origin);
        }

        $this->assertSame($expected, WidgetOrigins::cross_origin($request));
    }
}
