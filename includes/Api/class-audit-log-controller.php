<?php

/**
 * Audit Log Controller - admin list + filter surface for the system-wide
 * audit log.
 *
 * Ports the Laravel reference Admin\AuditLogController. Gated by the existing
 * escalated_audit_view capability (seeded by the Activator) so no new
 * capability is introduced. Read-only: audit rows are written at mutation
 * sites via AuditLog::record() and are never edited or deleted here.
 *
 * Routes (namespace escalated/v1):
 *   GET /admin/audit-logs   index (paginated, filterable)
 */

namespace Escalated\Api;

use Escalated\Models\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Audit_Log_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'admin/audit-logs';

    /**
     * Register routes.
     */
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/'.$this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'permission_view'],
                'args' => [
                    'user_id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                    'action' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'auditable_type' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'date_from' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'date_to' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'page' => ['type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'],
                    'per_page' => ['type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);
    }

    /**
     * Login + capability guard (escalated_audit_view).
     *
     * @return bool|WP_Error
     */
    public function permission_view()
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'escalated_unauthorized',
                __('You must be logged in.', 'escalated'),
                ['status' => 401]
            );
        }

        if (! current_user_can('escalated_audit_view')) {
            return new WP_Error(
                'escalated_forbidden',
                __('You do not have permission to view the audit log.', 'escalated'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * GET /admin/audit-logs
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function index(WP_REST_Request $request)
    {
        $filters = [
            'user_id' => $request->get_param('user_id') ? (int) $request->get_param('user_id') : null,
            'action' => (string) $request->get_param('action'),
            'auditable_type' => (string) $request->get_param('auditable_type'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ];

        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        $per_page = $per_page > 0 ? min(200, $per_page) : 50;
        $offset = ($page - 1) * $per_page;

        $rows = AuditLog::all($filters, $per_page, $offset);
        $total = AuditLog::count($filters);

        return $this->success([
            'logs' => array_map([$this, 'format_log'], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
            'filters' => [
                'user_id' => $filters['user_id'],
                'action' => $filters['action'],
                'auditable_type' => $filters['auditable_type'],
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
            ],
            'actions' => AuditLog::distinct_actions(),
            'resource_types' => AuditLog::distinct_types(),
        ]);
    }

    /**
     * Project an audit row into the response shape.
     *
     * @param  object  $row
     * @return array<string, mixed>
     */
    private function format_log($row): array
    {
        $user = null;
        if (! empty($row->user_id)) {
            $wp_user = get_userdata((int) $row->user_id);
            if ($wp_user) {
                $user = [
                    'id' => (int) $wp_user->ID,
                    'name' => $wp_user->display_name ?: $wp_user->user_login,
                    'email' => $wp_user->user_email,
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            'user' => $user,
            'action' => $row->action,
            'auditable_type' => $row->auditable_type,
            'auditable_id' => $row->auditable_id !== null ? (int) $row->auditable_id : null,
            'old_values' => $this->decode_json($row->old_values),
            'new_values' => $this->decode_json($row->new_values),
            'ip_address' => $row->ip_address,
            'user_agent' => $row->user_agent,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Decode a stored JSON column back into an array (or null).
     *
     * @param  string|null  $value
     * @return array<string, mixed>|null
     */
    private function decode_json($value)
    {
        if (empty($value)) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
