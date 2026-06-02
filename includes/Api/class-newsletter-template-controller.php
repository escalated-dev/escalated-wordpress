<?php

namespace Escalated\Api;

use Escalated\Models\Newsletter\NewsletterTemplate;
use Escalated\Services\Newsletter\NewsletterConfig;
use WP_REST_Request;
use WP_REST_Server;

class Newsletter_Template_Controller extends Base_Controller
{
    use NewsletterHttp;

    public function register_routes(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }

        $ns = $this->namespace;
        $manage = [$this, 'newsletters_manage_check'];

        register_rest_route($ns, '/admin/newsletters/templates/new', [[
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'create_form'],
            'permission_callback' => $manage,
        ]]);

        register_rest_route($ns, '/admin/newsletters/templates/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'show'], 'permission_callback' => $manage],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'destroy'], 'permission_callback' => $manage],
        ]);

        register_rest_route($ns, '/admin/newsletters/templates', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'store'], 'permission_callback' => $manage],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        unset($request);
        global $wpdb;
        $templates = $wpdb->get_results(
            'SELECT * FROM '.NewsletterTemplate::table().' ORDER BY created_at DESC'
        ) ?: [];

        return $this->inertia('Escalated/Admin/Newsletters/Templates/Index', ['templates' => $templates]);
    }

    public function create_form(WP_REST_Request $request)
    {
        unset($request);

        return $this->inertia('Escalated/Admin/Newsletters/Templates/Create', [
            'themes' => NewsletterConfig::discover_themes(),
        ]);
    }

    public function store(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $err = $this->validate_template($body);
        if ($err) {
            return $err;
        }
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(NewsletterTemplate::table(), [
            'name' => sanitize_text_field((string) $body['name']),
            'theme' => sanitize_text_field((string) $body['theme']),
            'subject_template' => isset($body['subject_template']) ? sanitize_text_field((string) $body['subject_template']) : null,
            'body_markdown' => (string) $body['body_markdown'],
            'merge_fields_schema' => isset($body['merge_fields_schema']) ? wp_json_encode($body['merge_fields_schema']) : null,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/templates'));
    }

    public function show(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $template = NewsletterTemplate::find($id);
        if (! $template) {
            return $this->error('escalated_template_not_found', __('Template not found.', 'escalated'), 404);
        }

        return $this->inertia('Escalated/Admin/Newsletters/Templates/Show', [
            'template' => $template,
            'themes' => NewsletterConfig::discover_themes(),
            'isNew' => false,
        ]);
    }

    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if (! NewsletterTemplate::find($id)) {
            return $this->error('escalated_template_not_found', __('Template not found.', 'escalated'), 404);
        }
        $body = $this->parse_body($request);
        $err = $this->validate_template($body);
        if ($err) {
            return $err;
        }
        global $wpdb;
        $wpdb->update(NewsletterTemplate::table(), [
            'name' => sanitize_text_field((string) $body['name']),
            'theme' => sanitize_text_field((string) $body['theme']),
            'subject_template' => isset($body['subject_template']) ? sanitize_text_field((string) $body['subject_template']) : null,
            'body_markdown' => (string) $body['body_markdown'],
            'merge_fields_schema' => isset($body['merge_fields_schema']) ? wp_json_encode($body['merge_fields_schema']) : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/templates/'.$id));
    }

    public function destroy(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        global $wpdb;
        $wpdb->delete(NewsletterTemplate::table(), ['id' => $id]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/templates'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function validate_template(array $body): ?\WP_Error
    {
        if (empty($body['name'])) {
            return $this->error('escalated_validation', __('Name is required.', 'escalated'), 400);
        }
        if (empty($body['theme'])) {
            return $this->error('escalated_validation', __('Theme is required.', 'escalated'), 400);
        }
        if (! isset($body['body_markdown']) || $body['body_markdown'] === '') {
            return $this->error('escalated_validation', __('Body is required.', 'escalated'), 400);
        }

        return null;
    }
}
