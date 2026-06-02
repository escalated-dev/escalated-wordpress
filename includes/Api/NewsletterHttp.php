<?php

namespace Escalated\Api;

use Escalated\Services\Newsletter\NewsletterConfig;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared helpers for newsletter admin REST controllers.
 */
trait NewsletterHttp
{
    public function newsletters_enabled_check()
    {
        if (! NewsletterConfig::is_enabled()) {
            return new WP_Error('escalated_not_found', __('Not found.', 'escalated'), ['status' => 404]);
        }

        return true;
    }

    /**
     * @return bool|WP_Error
     */
    public function newsletters_manage_check()
    {
        $gate = $this->newsletters_enabled_check();
        if (is_wp_error($gate)) {
            return $gate;
        }
        if (! is_user_logged_in()) {
            return new WP_Error('escalated_unauthorized', __('You must be logged in.', 'escalated'), ['status' => 401]);
        }
        if (! current_user_can('escalated_newsletters_manage')) {
            return new WP_Error('escalated_forbidden', __('You do not have permission to manage newsletters.', 'escalated'), ['status' => 403]);
        }

        return true;
    }

    /**
     * @return bool|WP_Error
     */
    public function newsletters_send_check()
    {
        $gate = $this->newsletters_manage_check();
        if (is_wp_error($gate)) {
            return $gate;
        }
        if (! current_user_can('escalated_newsletters_send')) {
            return new WP_Error('escalated_forbidden', __('You do not have permission to send newsletters.', 'escalated'), ['status' => 403]);
        }

        return true;
    }

    protected function inertia(string $component, array $props): WP_REST_Response
    {
        return $this->success(['component' => $component, 'props' => $props]);
    }

    protected function redirect_response(string $url, array $extra = []): WP_REST_Response
    {
        return $this->success(array_merge(['redirect' => $url], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    protected function parse_body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }
        $body = $request->get_body_params();

        return is_array($body) ? $body : [];
    }

    protected function paginate_array(array $items, int $page, int $per_page): array
    {
        $total = count($items);
        $offset = max(0, ($page - 1) * $per_page);

        return [
            'data' => array_slice($items, $offset, $per_page),
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $per_page)),
        ];
    }
}
