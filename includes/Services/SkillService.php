<?php

namespace Escalated\Services;

use Escalated\Admin\Admin_Users;
use Escalated\Escalated;
use Escalated\Models\Department;
use Escalated\Models\Tag;
use WP_Error;

/**
 * Admin skills CRUD and form payloads (skills-management domain contract).
 */
class SkillService
{
    private static function skills_table(): string
    {
        return Escalated::table('skills');
    }

    private static function routing_tags_table(): string
    {
        return Escalated::table('skill_routing_tags');
    }

    private static function routing_departments_table(): string
    {
        return Escalated::table('skill_routing_departments');
    }

    private static function agent_skills_table(): string
    {
        return Escalated::table('agent_skills');
    }

    /**
     * @param  string|null  $mysql  WordPress mysql datetime string.
     */
    public static function to_iso8601(?string $mysql): ?string
    {
        if ($mysql === null || $mysql === '') {
            return null;
        }

        $gmt = get_gmt_from_date($mysql, 'Y-m-d H:i:s');
        $ts = strtotime($gmt.' UTC');

        return $ts ? gmdate('c', $ts) : null;
    }

    /**
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public static function available_agents_wire(): array
    {
        $out = [];
        $users = get_users([
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => 'all',
        ]);
        foreach ($users as $user) {
            if (! Admin_Users::user_is_agent($user)) {
                continue;
            }
            $out[] = [
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function available_tags_wire(): array
    {
        $out = [];
        foreach (Tag::all() as $tag) {
            $out[] = [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function available_departments_wire(): array
    {
        $out = [];
        foreach (Department::all(['is_active' => 1]) as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *     available_agents: array,
     *     available_tags: array,
     *     available_departments: array
     * }
     */
    public static function get_form_context(): array
    {
        return [
            'available_agents' => self::available_agents_wire(),
            'available_tags' => self::available_tags_wire(),
            'available_departments' => self::available_departments_wire(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list_for_admin(): array
    {
        global $wpdb;
        $skills = self::skills_table();
        $rt = self::routing_tags_table();
        $rd = self::routing_departments_table();
        $as = self::agent_skills_table();

        $rows = $wpdb->get_results(
            "SELECT s.id, s.name, s.updated_at,
                (SELECT COUNT(*) FROM {$as} a WHERE a.skill_id = s.id) AS agents_count,
                (SELECT COUNT(*) FROM {$rt} t WHERE t.skill_id = s.id) AS routing_tags_count,
                (SELECT COUNT(*) FROM {$rd} d WHERE d.skill_id = s.id) AS routing_departments_count
             FROM {$skills} s
             ORDER BY s.name ASC"
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'agents_count' => (int) $row->agents_count,
                'routing_tags_count' => (int) $row->routing_tags_count,
                'routing_departments_count' => (int) $row->routing_departments_count,
                'updated_at' => self::to_iso8601($row->updated_at),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find_for_edit(int $id): ?array
    {
        global $wpdb;
        $skills = self::skills_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$skills} WHERE id = %d", $id));
        if (! $row) {
            return null;
        }

        $rt = self::routing_tags_table();
        $rd = self::routing_departments_table();
        $as = self::agent_skills_table();

        $tag_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT tag_id FROM {$rt} WHERE skill_id = %d ORDER BY tag_id ASC",
            $id
        )) ?: []);

        $dept_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT department_id FROM {$rd} WHERE skill_id = %d ORDER BY department_id ASC",
            $id
        )) ?: []);

        $agent_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, proficiency FROM {$as} WHERE skill_id = %d ORDER BY user_id ASC",
            $id
        )) ?: [];

        $agents = [];
        foreach ($agent_rows as $ar) {
            $agents[] = [
                'user_id' => (int) $ar->user_id,
                'proficiency' => (int) $ar->proficiency,
            ];
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'routing_tag_ids' => $tag_ids,
            'routing_department_ids' => $dept_ids,
            'agents' => $agents,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return true|WP_Error
     */
    public static function validate_payload(array $payload, ?int $exclude_skill_id = null)
    {
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        if ($name === '') {
            return new WP_Error('escalated_skill_validation', __('Skill name is required.', 'escalated'), ['status' => 422]);
        }
        if (strlen($name) > 100) {
            return new WP_Error('escalated_skill_validation', __('Skill name must be 100 characters or fewer.', 'escalated'), ['status' => 422]);
        }

        global $wpdb;
        $skills = self::skills_table();
        if ($exclude_skill_id) {
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$skills} WHERE name = %s AND id <> %d",
                $name,
                $exclude_skill_id
            ));
        } else {
            $dup = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$skills} WHERE name = %s", $name));
        }
        if ($dup) {
            return new WP_Error('escalated_skill_validation', __('A skill with this name already exists.', 'escalated'), ['status' => 422]);
        }

        $routing_tag_ids = self::normalize_id_list($payload['routing_tag_ids'] ?? []);
        foreach ($routing_tag_ids as $tid) {
            if (! Tag::find($tid)) {
                return new WP_Error('escalated_skill_validation', __('One or more tags do not exist.', 'escalated'), ['status' => 422]);
            }
        }

        $routing_department_ids = self::normalize_id_list($payload['routing_department_ids'] ?? []);
        foreach ($routing_department_ids as $did) {
            if (! Department::find($did)) {
                return new WP_Error('escalated_skill_validation', __('One or more departments do not exist.', 'escalated'), ['status' => 422]);
            }
        }

        $agents = self::normalize_agents_payload($payload['agents'] ?? []);
        foreach ($agents as $agent) {
            $u = get_userdata($agent['user_id']);
            if (! $u || ! Admin_Users::user_is_agent($u)) {
                return new WP_Error('escalated_skill_validation', __('Each agent must be an existing Escalated agent user.', 'escalated'), ['status' => 422]);
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return int|WP_Error New skill id.
     */
    public static function create(array $payload)
    {
        $validated = self::validate_payload($payload, null);
        if (is_wp_error($validated)) {
            return $validated;
        }

        global $wpdb;
        $name = trim((string) $payload['name']);
        $slug = self::unique_slug(sanitize_title($name));
        $now = current_time('mysql');
        $routing_tag_ids = self::normalize_id_list($payload['routing_tag_ids'] ?? []);
        $routing_department_ids = self::normalize_id_list($payload['routing_department_ids'] ?? []);
        $agents = self::normalize_agents_payload($payload['agents'] ?? []);

        $wpdb->query('START TRANSACTION');

        $ok = $wpdb->insert(self::skills_table(), [
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s']);

        if ($ok === false) {
            $wpdb->query('ROLLBACK');

            return new WP_Error('escalated_skill_create', __('Could not create skill.', 'escalated'), ['status' => 500]);
        }

        $skill_id = (int) $wpdb->insert_id;
        self::replace_routing_and_agents($skill_id, $routing_tag_ids, $routing_department_ids, $agents);

        $wpdb->query('COMMIT');

        return $skill_id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return true|WP_Error
     */
    public static function update(int $id, array $payload)
    {
        global $wpdb;
        $skills = self::skills_table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$skills} WHERE id = %d", $id));
        if (! $exists) {
            return new WP_Error('escalated_skill_not_found', __('Skill not found.', 'escalated'), ['status' => 404]);
        }

        $validated = self::validate_payload($payload, $id);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $name = trim((string) $payload['name']);
        $routing_tag_ids = self::normalize_id_list($payload['routing_tag_ids'] ?? []);
        $routing_department_ids = self::normalize_id_list($payload['routing_department_ids'] ?? []);
        $agents = self::normalize_agents_payload($payload['agents'] ?? []);
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');

        $wpdb->update(
            $skills,
            [
                'name' => $name,
                'slug' => self::unique_slug(sanitize_title($name), $id),
                'updated_at' => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        self::replace_routing_and_agents($id, $routing_tag_ids, $routing_department_ids, $agents);

        $wpdb->query('COMMIT');

        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function delete(int $id)
    {
        global $wpdb;
        $skills = self::skills_table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$skills} WHERE id = %d", $id));
        if (! $exists) {
            return new WP_Error('escalated_skill_not_found', __('Skill not found.', 'escalated'), ['status' => 404]);
        }

        $wpdb->query('START TRANSACTION');
        $wpdb->delete(self::agent_skills_table(), ['skill_id' => $id], ['%d']);
        $wpdb->delete(self::routing_tags_table(), ['skill_id' => $id], ['%d']);
        $wpdb->delete(self::routing_departments_table(), ['skill_id' => $id], ['%d']);
        $wpdb->delete($skills, ['id' => $id], ['%d']);
        $wpdb->query('COMMIT');

        return true;
    }

    /**
     * @param  array<int|string>  $ids
     * @return int[]
     */
    private static function normalize_id_list($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $v) {
            $out[] = (int) $v;
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @param  mixed  $agents
     * @return array<int, array{user_id: int, proficiency: int}>
     */
    private static function normalize_agents_payload($agents): array
    {
        if (! is_array($agents)) {
            return [];
        }
        $out = [];
        foreach ($agents as $row) {
            if (! is_array($row)) {
                continue;
            }
            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($uid <= 0) {
                continue;
            }
            $prof = isset($row['proficiency']) ? (int) $row['proficiency'] : 3;
            if ($prof < 1) {
                $prof = 1;
            }
            if ($prof > 5) {
                $prof = 5;
            }
            $out[$uid] = ['user_id' => $uid, 'proficiency' => $prof];
        }

        return array_values($out);
    }

    private static function unique_slug(string $base, ?int $ignore_skill_id = null): string
    {
        global $wpdb;
        $table = self::skills_table();
        $slug = $base !== '' ? $base : 'skill';
        $candidate = $slug;
        $i = 2;
        while (true) {
            if ($ignore_skill_id) {
                $other = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE slug = %s AND id <> %d",
                    $candidate,
                    $ignore_skill_id
                ));
            } else {
                $other = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $candidate));
            }
            if (! $other) {
                return $candidate;
            }
            $candidate = $slug.'-'.$i;
            $i++;
        }
    }

    /**
     * @param  int[]  $routing_tag_ids
     * @param  int[]  $routing_department_ids
     * @param  array<int, array{user_id: int, proficiency: int}>  $agents
     */
    private static function replace_routing_and_agents(int $skill_id, array $routing_tag_ids, array $routing_department_ids, array $agents): void
    {
        global $wpdb;
        $wpdb->delete(self::routing_tags_table(), ['skill_id' => $skill_id], ['%d']);
        $wpdb->delete(self::routing_departments_table(), ['skill_id' => $skill_id], ['%d']);
        $wpdb->delete(self::agent_skills_table(), ['skill_id' => $skill_id], ['%d']);

        foreach ($routing_tag_ids as $tid) {
            $wpdb->insert(self::routing_tags_table(), [
                'skill_id' => $skill_id,
                'tag_id' => $tid,
            ], ['%d', '%d']);
        }

        foreach ($routing_department_ids as $did) {
            $wpdb->insert(self::routing_departments_table(), [
                'skill_id' => $skill_id,
                'department_id' => $did,
            ], ['%d', '%d']);
        }

        $now = current_time('mysql');
        foreach ($agents as $agent) {
            $wpdb->insert(self::agent_skills_table(), [
                'user_id' => $agent['user_id'],
                'skill_id' => $skill_id,
                'proficiency' => $agent['proficiency'],
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d', '%d', '%d', '%s', '%s']);
        }
    }
}
