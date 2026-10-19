<?php

/**
 * Widget CORS - lets allowed sites embed the widget.
 *
 * Applies to every route under /escalated/v1/widget (including the live chat
 * routes). Only origins on the allow list (see WidgetOrigins) get a CORS
 * grant; such requests always run as a guest. WordPress core's own REST CORS
 * handler, which reflects any Origin with credentials, is switched off for
 * these routes. Same-origin requests and requests without an Origin header
 * are handled exactly as before.
 */

namespace Escalated\Api;

use Escalated\Services\WidgetOrigins;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

class Widget_Cors
{
    public const ROUTE_PREFIX = '/escalated/v1/widget';

    public const SCRIPT_ROUTE = '/escalated/v1/widget/embed.js';

    public const ALLOW_METHODS = 'GET, POST, OPTIONS';

    public const ALLOW_HEADERS = 'Accept, Authorization, Content-Type, X-Requested-With';

    public const MAX_AGE = 600;

    public function register(): void
    {
        // Before core's rest_handle_options_request (priority 10).
        add_filter('rest_pre_dispatch', [$this, 'pre_dispatch'], 0, 3);
        add_filter('rest_post_dispatch', [$this, 'post_dispatch'], 10, 3);
        // Before core's rest_send_cors_headers (priority 10).
        add_filter('rest_pre_serve_request', [$this, 'disable_core_cors'], 0, 4);
    }

    public static function is_widget_route(WP_REST_Request $request): bool
    {
        $route = untrailingslashit((string) $request->get_route());

        return $route === self::ROUTE_PREFIX || strpos($route, self::ROUTE_PREFIX.'/') === 0;
    }

    private static function is_preflight(WP_REST_Request $request): bool
    {
        return $request->get_method() === 'OPTIONS' && $request->get_header('access_control_request_method') !== null;
    }

    /**
     * Answer preflights for the widget routes and make allowed cross-origin
     * requests anonymous.
     *
     * @param  mixed  $result
     * @return mixed
     */
    public function pre_dispatch($result, $server, $request)
    {
        if (! $request instanceof WP_REST_Request || ! self::is_widget_route($request)) {
            return $result;
        }

        $origin = WidgetOrigins::allowed_cross_origin($request);

        if (self::is_preflight($request)) {
            $response = new WP_REST_Response(null, 204);
            $response->header('Vary', 'Origin');
            if ($origin !== null) {
                $response->header('Access-Control-Allow-Methods', self::ALLOW_METHODS);
                $response->header('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
                $response->header('Access-Control-Max-Age', (string) self::MAX_AGE);
                self::grant($response, $origin);
            }

            return $response;
        }

        if ($origin !== null && $request->get_route() !== self::SCRIPT_ROUTE) {
            // A cross-site embed is always anonymous: never act as whoever is
            // signed in to WordPress in the same browser.
            wp_set_current_user(0);
        }

        return $result;
    }

    /**
     * Add the CORS grant to every answer for an allowed site, errors included.
     *
     * @param  mixed  $response
     * @return mixed
     */
    public function post_dispatch($response, $server, $request)
    {
        if (! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request
            || ! self::is_widget_route($request) || $request->get_route() === self::SCRIPT_ROUTE
            || self::is_preflight($request)) {
            return $response;
        }

        $origin = WidgetOrigins::allowed_cross_origin($request);
        if ($origin !== null) {
            self::grant($response, $origin);
        }

        return $response;
    }

    /**
     * Keep WordPress core's CORS handler (any Origin, with credentials) off
     * the widget routes. It is put back afterwards for anything else served
     * in the same process.
     *
     * @param  mixed  $served
     * @return mixed
     */
    public function disable_core_cors($served, $result = null, $request = null, $server = null)
    {
        if ($request instanceof WP_REST_Request && self::is_widget_route($request)
            && has_filter('rest_pre_serve_request', 'rest_send_cors_headers') !== false) {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
            add_filter('rest_pre_serve_request', [$this, 'restore_core_cors'], PHP_INT_MAX);
        }

        return $served;
    }

    /**
     * @param  mixed  $served
     * @return mixed
     */
    public function restore_core_cors($served)
    {
        remove_filter('rest_pre_serve_request', [$this, 'restore_core_cors'], PHP_INT_MAX);
        add_filter('rest_pre_serve_request', 'rest_send_cors_headers');

        return $served;
    }

    private static function grant(WP_HTTP_Response $response, string $origin): void
    {
        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Access-Control-Expose-Headers', 'Retry-After');

        $headers = $response->get_headers();
        $vary = array_filter(array_map('trim', explode(',', (string) ($headers['Vary'] ?? ''))));
        if (! in_array('Origin', $vary, true)) {
            $vary[] = 'Origin';
        }
        $response->header('Vary', implode(', ', $vary));
    }
}
