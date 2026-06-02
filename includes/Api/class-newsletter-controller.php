<?php

namespace Escalated\Api;

use Escalated\Models\Contact;
use Escalated\Models\Newsletter\Newsletter;
use Escalated\Models\Newsletter\NewsletterDelivery;
use Escalated\Models\Newsletter\NewsletterList;
use Escalated\Models\Newsletter\NewsletterListMember;
use Escalated\Models\Newsletter\NewsletterTemplate;
use Escalated\Services\Newsletter\NewsletterConfig;
use Escalated\Services\Newsletter\NewsletterPlanner;
use Escalated\Services\Newsletter\NewsletterRenderer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Newsletter_Controller extends Base_Controller
{
    use NewsletterHttp;

    public function register_routes(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }

        $ns = $this->namespace;
        $manage = [$this, 'newsletters_manage_check'];
        $send = [$this, 'newsletters_send_check'];

        $static = [
            ['path' => '/admin/newsletters/new', 'methods' => WP_REST_Server::READABLE, 'callback' => 'create_form'],
            ['path' => '/admin/newsletters/preview', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'preview'],
            ['path' => '/admin/newsletters/test', 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'test_send', 'perm' => $send],
        ];
        foreach ($static as $route) {
            register_rest_route($ns, $route['path'], [[
                'methods' => $route['methods'],
                'callback' => [$this, $route['callback']],
                'permission_callback' => $route['perm'] ?? $manage,
            ]]);
        }

        register_rest_route($ns, '/admin/newsletters/(?P<id>\d+)/edit', [[
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'edit_form'],
            'permission_callback' => $manage,
        ]]);

        register_rest_route($ns, '/admin/newsletters/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'show'], 'permission_callback' => $manage],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'destroy'], 'permission_callback' => $manage],
        ]);

        register_rest_route($ns, '/admin/newsletters', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'store'], 'permission_callback' => $manage],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        $tab = sanitize_text_field((string) ($request->get_param('tab') ?: 'drafts'));
        $statuses = match ($tab) {
            'scheduled' => ['scheduled', 'sending', 'paused'],
            'sent' => ['sent', 'failed'],
            default => ['draft'],
        };
        global $wpdb;
        $table = Newsletter::table();
        $lists = NewsletterList::table();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, l.id AS list_id, l.name AS list_name
             FROM {$table} n
             LEFT JOIN {$lists} l ON l.id = n.target_list_id
             WHERE n.status IN ({$placeholders})
             ORDER BY n.created_at DESC
             LIMIT 50",
            ...$statuses
        )) ?: [];

        $newsletters = array_map(function ($row) {
            $row->target_list = $row->list_id ? (object) ['id' => (int) $row->list_id, 'name' => $row->list_name] : null;
            unset($row->list_id, $row->list_name);

            return $row;
        }, $rows);

        return $this->inertia('Escalated/Admin/Newsletters/Index', [
            'newsletters' => $this->paginate_array($newsletters, 1, 50),
            'tab' => $tab,
        ]);
    }

    public function create_form(WP_REST_Request $request)
    {
        unset($request);

        return $this->inertia('Escalated/Admin/Newsletters/Compose', $this->compose_props());
    }

    public function store(WP_REST_Request $request)
    {
        $data = $this->validate_form($request);
        if (is_wp_error($data)) {
            return $data;
        }
        if (in_array($data['status'], ['scheduled', 'sending'], true)) {
            $send = $this->newsletters_send_check();
            if (is_wp_error($send)) {
                return $send;
            }
            if (! NewsletterConfig::mail_configured()) {
                return $this->error('escalated_mail_not_configured', __('Outbound mail is not configured.', 'escalated'), 400);
            }
        }

        $id = Newsletter::create(array_merge($data, ['created_by' => get_current_user_id()]));
        $newsletter = Newsletter::find($id);
        if ($data['status'] === 'sending' && $newsletter) {
            (new NewsletterPlanner(new \Escalated\Services\Newsletter\ContactSegmentResolver, new \Escalated\Services\Newsletter\BounceSuppressionStore))->plan($newsletter);
        }

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/'.$id));
    }

    public function show(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $newsletter = Newsletter::find($id);
        if (! $newsletter) {
            return $this->error('escalated_newsletter_not_found', __('Newsletter not found.', 'escalated'), 404);
        }
        $list = NewsletterList::find((int) $newsletter->target_list_id);
        $newsletter->target_list = $list;

        global $wpdb;
        $dtable = NewsletterDelivery::table();
        $ctable = Contact::table();
        $status_filter = $request->get_param('status');
        $where = 'd.newsletter_id = %d AND d.is_test = 0';
        $params = [$id];
        if ($status_filter) {
            $where .= ' AND d.status = %s';
            $params[] = sanitize_text_field((string) $status_filter);
        }
        $deliveries = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, c.name AS contact_name, c.email AS contact_email
             FROM {$dtable} d
             LEFT JOIN {$ctable} c ON c.id = d.contact_id
             WHERE {$where}
             ORDER BY d.id DESC
             LIMIT 100",
            ...$params
        )) ?: [];

        foreach ($deliveries as $d) {
            $d->contact = (object) ['id' => (int) $d->contact_id, 'name' => $d->contact_name, 'email' => $d->contact_email];
        }

        return $this->inertia('Escalated/Admin/Newsletters/Show', [
            'newsletter' => $newsletter,
            'deliveries' => $this->paginate_array($deliveries, 1, 100),
            'topClicks' => [],
            'tab' => sanitize_text_field((string) ($request->get_param('tab') ?: 'overview')),
        ]);
    }

    public function edit_form(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $newsletter = Newsletter::find($id);
        if (! $newsletter) {
            return $this->error('escalated_newsletter_not_found', __('Newsletter not found.', 'escalated'), 404);
        }
        if (! in_array($newsletter->status, ['draft', 'scheduled'], true)) {
            return $this->error('escalated_unprocessable', __('Only drafts and scheduled newsletters can be edited.', 'escalated'), 422);
        }

        return $this->inertia('Escalated/Admin/Newsletters/Edit', $this->compose_props() + ['newsletter' => $newsletter]);
    }

    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $newsletter = Newsletter::find($id);
        if (! $newsletter) {
            return $this->error('escalated_newsletter_not_found', __('Newsletter not found.', 'escalated'), 404);
        }
        $data = $this->validate_form($request);
        if (is_wp_error($data)) {
            return $data;
        }
        if (in_array($data['status'], ['scheduled', 'sending'], true)) {
            $send = $this->newsletters_send_check();
            if (is_wp_error($send)) {
                return $send;
            }
        }
        Newsletter::update($id, $data);
        if ($data['status'] === 'sending') {
            $updated = Newsletter::find($id);
            if ($updated) {
                (new NewsletterPlanner(new \Escalated\Services\Newsletter\ContactSegmentResolver, new \Escalated\Services\Newsletter\BounceSuppressionStore))->plan($updated);
            }
        }

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/'.$id));
    }

    public function destroy(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $newsletter = Newsletter::find($id);
        if (! $newsletter) {
            return $this->error('escalated_newsletter_not_found', __('Newsletter not found.', 'escalated'), 404);
        }
        if ($newsletter->status !== 'draft') {
            return $this->error('escalated_unprocessable', __('Only drafts can be deleted.', 'escalated'), 422);
        }
        global $wpdb;
        $wpdb->delete(Newsletter::table(), ['id' => $id]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters'));
    }

    public function preview(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $n = (object) array_merge([
            'subject' => $body['subject'] ?? '',
            'body_markdown' => $body['body_markdown'] ?? '',
            'theme' => $body['theme'] ?? 'default',
            'from_email' => $body['from_email'] ?? 'preview@example.test',
            'template_id' => null,
        ], $body);
        $contact = (object) ['id' => 0, 'email' => 'preview@example.test', 'name' => 'Preview User', 'metadata' => '{}'];
        $delivery = (object) [
            'tracking_token' => 'preview',
            'newsletter_id' => 0,
            'contact_id' => 0,
            'email_at_send' => $contact->email,
        ];
        $renderer = new NewsletterRenderer;
        $html = $renderer->render($delivery, $n, $contact, null);

        return $this->success(['html' => $html]);
    }

    public function test_send(WP_REST_Request $request)
    {
        $send = $this->newsletters_send_check();
        if (is_wp_error($send)) {
            return $send;
        }
        $data = $this->validate_form($request);
        if (is_wp_error($data)) {
            return $data;
        }
        if (! NewsletterConfig::mail_configured()) {
            return $this->error('escalated_mail_not_configured', __('Outbound mail is not configured.', 'escalated'), 400);
        }

        $user = wp_get_current_user();
        $n = (object) $data;
        $contact = (object) ['id' => $user->ID, 'email' => $user->user_email, 'name' => $user->display_name, 'metadata' => '{}'];
        $delivery = (object) [
            'tracking_token' => wp_generate_password(40, false, false),
            'newsletter_id' => 0,
            'contact_id' => $user->ID,
            'email_at_send' => $user->user_email,
            'is_test' => 1,
        ];
        $renderer = new NewsletterRenderer;
        $html = $renderer->render($delivery, $n, $contact, null);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from = ! empty($data['from_name'])
            ? sprintf('%s <%s>', $data['from_name'], $data['from_email'])
            : $data['from_email'];
        $headers[] = 'From: '.$from;
        wp_mail($user->user_email, '[TEST] '.$data['subject'], $html, $headers);

        return $this->success(['ok' => true]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function validate_form(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $subject = isset($body['subject']) ? sanitize_text_field((string) $body['subject']) : '';
        if ($subject === '') {
            return $this->error('escalated_validation', __('Subject is required.', 'escalated'), 400);
        }
        if (strlen($subject) > 998) {
            return $this->error('escalated_validation', __('Subject is too long.', 'escalated'), 400);
        }
        $from_email = isset($body['from_email']) ? sanitize_email((string) $body['from_email']) : '';
        if (! is_email($from_email)) {
            return $this->error('escalated_validation', __('From email is required.', 'escalated'), 400);
        }
        $target_list_id = isset($body['target_list_id']) ? absint($body['target_list_id']) : 0;
        if ($target_list_id <= 0 || ! NewsletterList::find($target_list_id)) {
            return $this->error('escalated_validation', __('Target list is required.', 'escalated'), 400);
        }
        $status = $body['status'] ?? 'draft';
        if (! in_array($status, ['draft', 'scheduled', 'sending'], true)) {
            return $this->error('escalated_validation', __('Invalid status.', 'escalated'), 400);
        }
        if (! empty($body['scheduled_at'])) {
            $ts = strtotime((string) $body['scheduled_at']);
            if ($ts === false || $ts <= time()) {
                return $this->error('escalated_validation', __('Scheduled time must be in the future.', 'escalated'), 400);
            }
        }

        return [
            'subject' => $subject,
            'from_email' => $from_email,
            'from_name' => isset($body['from_name']) ? sanitize_text_field((string) $body['from_name']) : null,
            'reply_to' => ! empty($body['reply_to']) ? sanitize_email((string) $body['reply_to']) : null,
            'target_list_id' => $target_list_id,
            'template_id' => ! empty($body['template_id']) ? absint($body['template_id']) : null,
            'theme' => isset($body['theme']) ? sanitize_text_field((string) $body['theme']) : null,
            'body_markdown' => isset($body['body_markdown']) ? (string) $body['body_markdown'] : null,
            'status' => $status,
            'scheduled_at' => ! empty($body['scheduled_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $body['scheduled_at'])) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compose_props(): array
    {
        global $wpdb;
        $lists = $wpdb->get_results(
            'SELECT l.id, l.name, COUNT(m.id) AS member_count
             FROM '.NewsletterList::table().' l
             LEFT JOIN '.NewsletterListMember::table().' m ON m.list_id = l.id
             GROUP BY l.id ORDER BY l.name ASC'
        ) ?: [];
        $templates = $wpdb->get_results(
            'SELECT id, name FROM '.NewsletterTemplate::table().' ORDER BY name ASC'
        ) ?: [];

        return [
            'lists' => $lists,
            'templates' => $templates,
            'themes' => NewsletterConfig::discover_themes(),
            'mailConfigured' => NewsletterConfig::mail_configured(),
            'canSend' => true,
            'defaultFromEmail' => NewsletterConfig::default_from(),
            'defaultReplyTo' => NewsletterConfig::default_reply_to(),
            'defaultTheme' => NewsletterConfig::default_theme(),
        ];
    }
}
