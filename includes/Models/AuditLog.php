<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * System-wide audit log.
 *
 * Ports the Laravel reference {@see \Escalated\Laravel\Models\AuditLog} and the
 * Auditable trait into the plugin's table-based convention (static $wpdb
 * helpers, like {@see ApiToken} and {@see TwoFactor}). Where the per-ticket
 * escalated_ticket_activities table records ticket-scoped events, this table
 * records admin / configuration / security / user actions that happen outside
 * a single ticket: settings + webhook changes, role grants, API token and 2FA
 * lifecycle, and knowledge base CRUD.
 *
 * Every row captures: the acting user (nullable — some actions are system or
 * guest driven), the action string, an optional polymorphic subject
 * (auditable_type + auditable_id), JSON old/new value snapshots, and the
 * request IP + user agent. Rows are immutable once written.
 */
class AuditLog
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('audit_logs');
    }

    /**
     * Record an audit entry.
     *
     * The single helper reused at every mutation site. The acting user
     * defaults to the current WordPress user; Bearer-token endpoints (which
     * never call wp_set_current_user) pass the resolved token user explicitly.
     *
     * @param  string  $action  Dotted action verb, e.g. "settings.updated".
     * @param  string|null  $auditable_type  Logical subject type, e.g. "Article".
     * @param  int|null  $auditable_id  Subject primary key, when applicable.
     * @param  array<string, mixed>|null  $old_values  Prior state snapshot.
     * @param  array<string, mixed>|null  $new_values  New state snapshot.
     * @param  int|null  $user_id  Actor override; falls back to the current user.
     * @return int|false Inserted ID or false on failure.
     */
    public static function record(
        string $action,
        ?string $auditable_type = null,
        $auditable_id = null,
        ?array $old_values = null,
        ?array $new_values = null,
        ?int $user_id = null
    ) {
        if ($user_id === null) {
            $current = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $user_id = $current > 0 ? $current : null;
        }

        return static::create([
            'user_id' => $user_id,
            'action' => $action,
            'auditable_type' => $auditable_type,
            'auditable_id' => $auditable_id !== null ? (int) $auditable_id : null,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'ip_address' => static::current_ip(),
            'user_agent' => static::current_user_agent(),
        ]);
    }

    /**
     * Insert an audit row.
     *
     * old_values / new_values arrays are JSON-encoded; created_at is stamped
     * when not supplied.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();

        foreach (['old_values', 'new_values'] as $json_key) {
            if (! array_key_exists($json_key, $data)) {
                continue;
            }
            $value = $data[$json_key];
            $data[$json_key] = ($value === null || $value === [])
                ? null
                : wp_json_encode($value);
        }

        if (empty($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Find an audit row by ID.
     *
     * @param  int  $id
     * @return object|null
     */
    public static function find($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id)
        );
    }

    /**
     * List audit rows, newest first, with optional filters and pagination.
     *
     * Supported filters: user_id, action, auditable_type, date_from, date_to.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, object>
     */
    public static function all(array $filters = [], int $limit = 50, int $offset = 0)
    {
        global $wpdb;
        $table = static::table();

        [$where, $values] = static::build_where($filters);

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values)) ?: [];
    }

    /**
     * Count audit rows matching the given filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function count(array $filters = []): int
    {
        global $wpdb;
        $table = static::table();

        [$where, $values] = static::build_where($filters);

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Distinct action verbs present in the log (for the filter dropdown).
     *
     * @return array<int, string>
     */
    public static function distinct_actions(): array
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_col("SELECT DISTINCT action FROM {$table} ORDER BY action ASC") ?: [];
    }

    /**
     * Distinct auditable types present in the log (for the filter dropdown).
     *
     * @return array<int, string>
     */
    public static function distinct_types(): array
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_col(
            "SELECT DISTINCT auditable_type FROM {$table} WHERE auditable_type IS NOT NULL AND auditable_type <> '' ORDER BY auditable_type ASC"
        ) ?: [];
    }

    /**
     * Build the shared WHERE clause + prepared values for the given filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    protected static function build_where(array $filters): array
    {
        $where = ['1=1'];
        $values = [];

        if (! empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = (int) $filters['user_id'];
        }

        if (! empty($filters['action'])) {
            $where[] = 'action = %s';
            $values[] = (string) $filters['action'];
        }

        if (! empty($filters['auditable_type'])) {
            $where[] = 'auditable_type = %s';
            $values[] = (string) $filters['auditable_type'];
        }

        if (! empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = (string) $filters['date_from'];
        }

        if (! empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = (string) $filters['date_to'].' 23:59:59';
        }

        return [implode(' AND ', $where), $values];
    }

    /**
     * Best-effort client IP for the current request.
     */
    protected static function current_ip(): ?string
    {
        $candidates = [];

        if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidates[] = trim($forwarded[0]);
        }

        if (! empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = (string) $_SERVER['REMOTE_ADDR'];
        }

        foreach ($candidates as $candidate) {
            $ip = filter_var($candidate, FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return substr($ip, 0, 45);
            }
        }

        return null;
    }

    /**
     * User agent for the current request, truncated to the column width.
     */
    protected static function current_user_agent(): ?string
    {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        $agent = sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']);

        return $agent !== '' ? substr($agent, 0, 255) : null;
    }
}
