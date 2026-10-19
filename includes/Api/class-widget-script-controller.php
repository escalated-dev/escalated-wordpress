<?php

/**
 * Widget Script Controller - serves the standalone widget bundle.
 *
 * GET /wp-json/escalated/v1/widget/embed.js returns
 * assets/widget/escalated-widget.js (built by @escalated-dev/escalated) so any
 * site can load the widget with a <script> tag pointing at this install.
 */

namespace Escalated\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Widget_Script_Controller extends Base_Controller
{
    public static function bundle_path(): string
    {
        return ESCALATED_PLUGIN_DIR.'assets/widget/escalated-widget.js';
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/widget/embed\\.js',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'show'],
                    'permission_callback' => '__return_true',
                ],
            ]
        );

        // A static callback, so registering routes again adds it only once.
        add_filter('rest_pre_serve_request', [self::class, 'serve_raw'], 10, 4);
    }

    /**
     * @return WP_REST_Response|\WP_Error
     */
    public function show(WP_REST_Request $request)
    {
        $path = static::bundle_path();
        if (! is_file($path)) {
            return $this->error('escalated_not_found', __('Widget script not found.', 'escalated'), 404);
        }

        $etag = '"'.md5_file($path).'"';
        $headers = [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Access-Control-Allow-Origin' => '*',
        ];

        if (in_array($etag, self::request_etags($request), true)) {
            return new WP_REST_Response(null, 304, $headers);
        }

        return new WP_REST_Response((string) file_get_contents($path), 200, $headers);
    }

    /**
     * Send the script itself instead of a JSON-encoded string.
     *
     * @param  bool  $served
     * @param  mixed  $result
     * @param  mixed  $request
     * @param  mixed  $server
     * @return bool
     */
    public static function serve_raw($served, $result, $request, $server)
    {
        if ($served || ! $request instanceof WP_REST_Request || $request->get_route() !== Widget_Cors::SCRIPT_ROUTE
            || ! $result instanceof WP_REST_Response || ! in_array($result->get_status(), [200, 304], true)) {
            return $served;
        }

        if ($result->get_status() === 200 && $request->get_method() !== 'HEAD') {
            echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript bundle.
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function request_etags(WP_REST_Request $request): array
    {
        $header = (string) $request->get_header('if_none_match');
        if ($header === '') {
            return [];
        }

        $etags = [];
        foreach (explode(',', $header) as $etag) {
            $etag = trim($etag);
            if (strpos($etag, 'W/') === 0) {
                $etag = substr($etag, 2);
            }
            if ($etag !== '') {
                $etags[] = $etag;
            }
        }

        return $etags;
    }
}
