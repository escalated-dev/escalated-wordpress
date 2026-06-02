<?php

namespace Escalated\Api;

use Escalated\Models\Contact;
use Escalated\Models\Newsletter\NewsletterList;
use Escalated\Models\Newsletter\NewsletterListMember;
use Escalated\Services\Newsletter\ContactSegmentResolver;
use Escalated\Services\Newsletter\NewsletterConfig;
use WP_REST_Request;
use WP_REST_Server;

class Newsletter_List_Controller extends Base_Controller
{
    use NewsletterHttp;

    public function register_routes(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }

        $ns = $this->namespace;
        $manage = [$this, 'newsletters_manage_check'];

        register_rest_route($ns, '/admin/newsletters/lists/new', [[
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'create_form'],
            'permission_callback' => $manage,
        ]]);

        register_rest_route($ns, '/admin/newsletters/lists/(?P<id>\d+)/members/(?P<contact_id>\d+)', [[
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'remove_member'],
            'permission_callback' => $manage,
        ]]);

        register_rest_route($ns, '/admin/newsletters/lists/(?P<id>\d+)/members', [[
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'add_member'],
            'permission_callback' => $manage,
        ]]);

        register_rest_route($ns, '/admin/newsletters/lists/(?P<id>\d+)/import', [[
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'import_csv'],
            'permission_callback' => $manage,
        ]]);

        register_rest_route($ns, '/admin/newsletters/lists/(?P<id>\d+)', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'show'], 'permission_callback' => $manage],
            ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'update'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'destroy'], 'permission_callback' => $manage],
        ]);

        register_rest_route($ns, '/admin/newsletters/lists', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'index'], 'permission_callback' => $manage],
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'store'], 'permission_callback' => $manage],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        unset($request);
        global $wpdb;
        $lists = $wpdb->get_results(
            'SELECT l.*, COUNT(m.id) AS member_count
             FROM '.NewsletterList::table().' l
             LEFT JOIN '.NewsletterListMember::table().' m ON m.list_id = l.id
             GROUP BY l.id ORDER BY l.name ASC'
        ) ?: [];

        foreach ($lists as $list) {
            $list->opted_out_count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM '.NewsletterListMember::table().' lm
                 INNER JOIN '.Contact::table().' c ON c.id = lm.contact_id
                 WHERE lm.list_id = %d AND c.marketing_opt_out_at IS NOT NULL',
                (int) $list->id
            ));
        }

        return $this->inertia('Escalated/Admin/Newsletters/Lists/Index', ['lists' => $lists]);
    }

    public function create_form(WP_REST_Request $request)
    {
        unset($request);

        return $this->inertia('Escalated/Admin/Newsletters/Lists/Create', []);
    }

    public function store(WP_REST_Request $request)
    {
        $body = $this->parse_body($request);
        $name = sanitize_text_field((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('escalated_validation', __('Name is required.', 'escalated'), 400);
        }
        $kind = $body['kind'] ?? '';
        if (! in_array($kind, ['static', 'dynamic'], true)) {
            return $this->error('escalated_validation', __('Invalid list kind.', 'escalated'), 400);
        }
        $id = NewsletterList::create([
            'name' => $name,
            'description' => isset($body['description']) ? sanitize_textarea_field((string) $body['description']) : null,
            'kind' => $kind,
            'filter_json' => isset($body['filter_json']) ? wp_json_encode($body['filter_json']) : null,
            'created_by' => get_current_user_id(),
        ]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/lists/'.$id));
    }

    public function show(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $list = NewsletterList::find($id);
        if (! $list) {
            return $this->error('escalated_list_not_found', __('List not found.', 'escalated'), 404);
        }
        global $wpdb;
        $members = $wpdb->get_results($wpdb->prepare(
            'SELECT lm.*, c.id AS contact_id, c.name, c.email
             FROM '.NewsletterListMember::table().' lm
             INNER JOIN '.Contact::table().' c ON c.id = lm.contact_id
             WHERE lm.list_id = %d
             ORDER BY lm.id DESC LIMIT 100',
            $id
        )) ?: [];
        foreach ($members as $m) {
            $m->contact = (object) ['id' => (int) $m->contact_id, 'name' => $m->name, 'email' => $m->email];
        }
        $list->member_count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.NewsletterListMember::table().' WHERE list_id = %d',
            $id
        ));
        $list->opted_out_count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.NewsletterListMember::table().' lm
             INNER JOIN '.Contact::table().' c ON c.id = lm.contact_id
             WHERE lm.list_id = %d AND c.marketing_opt_out_at IS NOT NULL',
            $id
        ));
        $matchCount = 0;
        if ($list->kind === NewsletterList::KIND_DYNAMIC) {
            $filter = json_decode((string) ($list->filter_json ?: '{"rules":[]}'), true) ?: ['rules' => []];
            $matchCount = (new ContactSegmentResolver)->count_matches($filter);
        }

        return $this->inertia('Escalated/Admin/Newsletters/Lists/Show', [
            'list' => $list,
            'members' => $this->paginate_array($members, 1, 100),
            'matchCount' => $matchCount,
        ]);
    }

    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if (! NewsletterList::find($id)) {
            return $this->error('escalated_list_not_found', __('List not found.', 'escalated'), 404);
        }
        $body = $this->parse_body($request);
        $attrs = [];
        if (isset($body['name'])) {
            $attrs['name'] = sanitize_text_field((string) $body['name']);
        }
        if (array_key_exists('description', $body)) {
            $attrs['description'] = sanitize_textarea_field((string) $body['description']);
        }
        if (array_key_exists('filter_json', $body)) {
            $attrs['filter_json'] = wp_json_encode($body['filter_json']);
        }
        if ($attrs !== []) {
            global $wpdb;
            $attrs['updated_at'] = current_time('mysql');
            $wpdb->update(NewsletterList::table(), $attrs, ['id' => $id]);
        }

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/lists/'.$id));
    }

    public function destroy(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        global $wpdb;
        $wpdb->delete(NewsletterList::table(), ['id' => $id]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/lists'));
    }

    public function add_member(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $list = NewsletterList::find($id);
        if (! $list) {
            return $this->error('escalated_list_not_found', __('List not found.', 'escalated'), 404);
        }
        if ($list->kind !== NewsletterList::KIND_STATIC) {
            return $this->error('escalated_unprocessable', __('Dynamic lists are filter-driven.', 'escalated'), 422);
        }
        $contact_id = absint($this->parse_body($request)['contact_id'] ?? 0);
        if ($contact_id <= 0 || ! Contact::find($contact_id)) {
            return $this->error('escalated_validation', __('Contact is required.', 'escalated'), 400);
        }
        global $wpdb;
        $table = NewsletterListMember::table();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE list_id = %d AND contact_id = %d",
            $id,
            $contact_id
        ));
        if (! $exists) {
            $wpdb->insert($table, [
                'list_id' => $id,
                'contact_id' => $contact_id,
                'added_at' => current_time('mysql'),
                'added_by' => get_current_user_id(),
            ]);
        }

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/lists/'.$id));
    }

    public function remove_member(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $list = NewsletterList::find($id);
        if (! $list) {
            return $this->error('escalated_list_not_found', __('List not found.', 'escalated'), 404);
        }
        if ($list->kind !== NewsletterList::KIND_STATIC) {
            return $this->error('escalated_unprocessable', __('Dynamic lists are filter-driven.', 'escalated'), 422);
        }
        global $wpdb;
        $wpdb->delete(NewsletterListMember::table(), [
            'list_id' => $id,
            'contact_id' => (int) $request->get_param('contact_id'),
        ]);

        return $this->redirect_response(rest_url('escalated/v1/admin/newsletters/lists/'.$id));
    }

    public function import_csv(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $list = NewsletterList::find($id);
        if (! $list) {
            return $this->error('escalated_list_not_found', __('List not found.', 'escalated'), 404);
        }
        if ($list->kind !== NewsletterList::KIND_STATIC) {
            return $this->error('escalated_unprocessable', __('Only static lists accept CSV import.', 'escalated'), 422);
        }
        $files = $request->get_file_params();
        if (empty($files['file']['tmp_name'])) {
            return $this->error('escalated_validation', __('CSV file is required.', 'escalated'), 400);
        }
        $handle = fopen($files['file']['tmp_name'], 'r');
        if (! $handle) {
            return $this->error('escalated_validation', __('Could not read CSV file.', 'escalated'), 400);
        }
        $imported = 0;
        global $wpdb;
        $table = NewsletterListMember::table();
        while (($row = fgetcsv($handle)) !== false) {
            $email = filter_var(trim($row[0] ?? ''), FILTER_VALIDATE_EMAIL);
            if (! $email) {
                continue;
            }
            $contact = Contact::find_or_create_by_email($email);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE list_id = %d AND contact_id = %d",
                $id,
                (int) $contact->id
            ));
            if (! $exists) {
                $wpdb->insert($table, [
                    'list_id' => $id,
                    'contact_id' => (int) $contact->id,
                    'added_at' => current_time('mysql'),
                    'added_by' => get_current_user_id(),
                ]);
                $imported++;
            }
        }
        fclose($handle);

        return $this->redirect_response(
            rest_url('escalated/v1/admin/newsletters/lists/'.$id),
            ['status' => sprintf(__('Imported %d contacts', 'escalated'), $imported)]
        );
    }
}
