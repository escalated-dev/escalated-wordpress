<?php

namespace Escalated\Services\Newsletter;

use Escalated\Models\Contact;
use Escalated\Models\Newsletter\NewsletterList;
use Escalated\Models\Newsletter\NewsletterListMember;

class ContactSegmentResolver
{
    private const ALLOWED_FIELDS = ['email', 'name', 'user_id', 'created_at', 'updated_at'];

    private const ALLOWED_OPS = ['=', '!=', '<', '>', '<=', '>=', 'like'];

    /**
     * @return array<int>
     */
    public function resolve(object $list): array
    {
        if ($list->kind === NewsletterList::KIND_STATIC) {
            return $this->static_member_ids((int) $list->id);
        }

        return $this->query_ids($this->filter_json($list));
    }

    /**
     * @return array<int>
     */
    public function resolve_sendable(object $list): array
    {
        if ($list->kind === NewsletterList::KIND_STATIC) {
            $ids = $this->static_member_ids((int) $list->id);
            if ($ids === []) {
                return [];
            }

            return $this->query_ids(['rules' => []], $ids, true);
        }

        return $this->query_ids($this->filter_json($list), null, true);
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    public function count_matches(array $filter): int
    {
        global $wpdb;
        $table = Contact::table();
        [$where, $params] = $this->build_where($filter);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE 1=1{$where}";
        if ($params === []) {
            return (int) $wpdb->get_var($sql);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * @return array<int>
     */
    private function static_member_ids(int $list_id): array
    {
        global $wpdb;
        $table = NewsletterListMember::table();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT contact_id FROM {$table} WHERE list_id = %d",
            $list_id
        ));

        return array_map('intval', $rows ?: []);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<int>|null  $limit_ids
     * @return array<int>
     */
    private function query_ids(array $filter, ?array $limit_ids = null, bool $sendable_only = false): array
    {
        global $wpdb;
        $table = Contact::table();
        [$where, $params] = $this->build_where($filter);
        if ($sendable_only) {
            $where .= ' AND marketing_opt_out_at IS NULL';
        }
        if ($limit_ids !== null) {
            if ($limit_ids === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($limit_ids), '%d'));
            $where .= " AND id IN ({$placeholders})";
            $params = array_merge($params, $limit_ids);
        }
        $sql = "SELECT id FROM {$table} WHERE 1=1{$where}";
        $rows = $params === [] ? $wpdb->get_col($sql) : $wpdb->get_col($wpdb->prepare($sql, ...$params));

        return array_map('intval', $rows ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private function filter_json(object $list): array
    {
        if (empty($list->filter_json)) {
            return ['rules' => []];
        }
        $decoded = json_decode((string) $list->filter_json, true);

        return is_array($decoded) ? $decoded : ['rules' => []];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{0: string, 1: array<int|string>}
     */
    private function build_where(array $filter): array
    {
        $clauses = [];
        $params = [];
        foreach ($filter['rules'] ?? [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $field = $rule['field'] ?? null;
            $op = $rule['op'] ?? '=';
            $value = $rule['value'] ?? null;
            if (! is_string($field) || $field === '') {
                continue;
            }
            if (! in_array($op, self::ALLOWED_OPS, true)) {
                continue;
            }
            if (str_starts_with($field, 'metadata.')) {
                $key = substr($field, strlen('metadata.'));
                if ($key === '' || ! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                    continue;
                }
                $needle = '%"'.$key.'":'.wp_json_encode($value).'%';
                $clauses[] = ' AND metadata LIKE %s';
                $params[] = $needle;
                continue;
            }
            if (! in_array($field, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            $clauses[] = " AND `{$field}` {$op} %s";
            $params[] = is_scalar($value) ? (string) $value : wp_json_encode($value);
        }

        return [implode('', $clauses), $params];
    }
}
