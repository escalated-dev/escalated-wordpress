<?php

namespace Escalated\Services;

use Escalated\Admin\Admin_Users;
use Escalated\Escalated;
use Escalated\Models\Ticket;

/**
 * Explicit tag/department → skill routing (ADR 2026-05-13).
 */
class SkillRoutingService
{
    /**
     * Skills required for a ticket: routing tags overlap OR routing department matches.
     *
     * @return int[]
     */
    public function required_skill_ids(object $ticket): array
    {
        global $wpdb;

        $tag_ids = Ticket::tag_ids((int) $ticket->id);
        $dept_id = ! empty($ticket->department_id) ? (int) $ticket->department_id : 0;

        $rt = Escalated::table('skill_routing_tags');
        $rd = Escalated::table('skill_routing_departments');

        $ids = [];

        if (! empty($tag_ids)) {
            $placeholders = implode(',', array_fill(0, count($tag_ids), '%d'));
            $sql = "SELECT DISTINCT skill_id FROM {$rt} WHERE tag_id IN ({$placeholders})";
            $rows = $wpdb->get_col($wpdb->prepare($sql, ...$tag_ids));
            if ($rows) {
                $ids = array_merge($ids, array_map('intval', $rows));
            }
        }

        if ($dept_id > 0) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT skill_id FROM {$rd} WHERE department_id = %d",
                $dept_id
            ));
            if ($rows) {
                $ids = array_merge($ids, array_map('intval', $rows));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Agents who have every required skill, ordered by proficiency sum desc then open ticket load asc.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public function find_matching_agents(object $ticket): array
    {
        $required = $this->required_skill_ids($ticket);

        if ($required === []) {
            return $this->all_agents_by_load();
        }

        global $wpdb;
        $as = Escalated::table('agent_skills');
        $n = count($required);
        $placeholders = implode(',', array_fill(0, $n, '%d'));
        $params = $required;
        $params[] = $n;

        $sql = "SELECT user_id, SUM(proficiency) AS prof_sum
                FROM {$as}
                WHERE skill_id IN ({$placeholders})
                GROUP BY user_id
                HAVING COUNT(DISTINCT skill_id) = %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];

        $candidates = [];
        foreach ($rows as $row) {
            $user = get_userdata((int) $row->user_id);
            if (! $user || ! Admin_Users::user_is_agent($user)) {
                continue;
            }
            $candidates[] = [
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
                '_prof_sum' => (int) $row->prof_sum,
                '_load' => Ticket::count_for_agent((int) $user->ID),
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['_prof_sum'] !== $b['_prof_sum']) {
                return $b['_prof_sum'] <=> $a['_prof_sum'];
            }
            if ($a['_load'] !== $b['_load']) {
                return $a['_load'] <=> $b['_load'];
            }

            return $a['id'] <=> $b['id'];
        });

        return array_map(function (array $row): array {
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
            ];
        }, $candidates);
    }

    /**
     * @return array<int, array{id: int, name: string, email: string}>
     */
    private function all_agents_by_load(): array
    {
        $rows = [];
        foreach (get_users(['orderby' => 'ID', 'order' => 'ASC']) as $user) {
            if (! Admin_Users::user_is_agent($user)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'email' => $user->user_email,
                '_load' => Ticket::count_for_agent((int) $user->ID),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['_load'] !== $b['_load']) {
                return $a['_load'] <=> $b['_load'];
            }

            return $a['id'] <=> $b['id'];
        });

        return array_map(function (array $row): array {
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
            ];
        }, $rows);
    }
}
