<?php

namespace Escalated\Models;

use Escalated\Escalated;
use Escalated\Services\TicketSnoozeService;
use Escalated\Services\TicketSplitService;

class Ticket
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('tickets');
    }

    /**
     * Find a ticket by ID.
     *
     * @param  int  $id
     * @return object|null
     */
    public static function find($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL", $id)
        );
    }

    /**
     * Find a ticket by reference string.
     *
     * @param  string  $ref
     * @return object|null
     */
    public static function find_by_reference($ref)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE reference = %s AND deleted_at IS NULL", $ref)
        );
    }

    /**
     * Find a ticket by guest token.
     *
     * @param  string  $token
     * @return object|null
     */
    public static function find_by_guest_token($token)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE guest_token = %s AND deleted_at IS NULL", $token)
        );
    }

    /**
     * Create a new ticket.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update a ticket.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Soft delete a ticket.
     *
     * @param  int  $id
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->update(
            $table,
            ['deleted_at' => current_time('mysql')],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Hard delete a ticket (permanent removal).
     *
     * @param  int  $id
     * @return bool
     */
    public static function hard_delete($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * Get all tickets with complex filtering and pagination.
     *
     * @param  array  $filters  {
     *                          Optional. Filter arguments.
     *
     * @type string $status        Filter by status.
     * @type string $priority      Filter by priority.
     * @type int $assigned_to   Filter by assigned agent.
     * @type bool $unassigned    Filter for unassigned tickets.
     * @type int $department_id Filter by department.
     * @type string $search        Search subject, reference, description.
     * @type bool $sla_breached  Filter by SLA breach.
     * @type array $tag_ids       Filter by tag IDs.
     * @type int $requester_id  Filter by requester.
     * @type string $sort_by       Column to sort by. Default 'created_at'.
     * @type string $sort_dir      Sort direction. Default 'DESC'.
     * @type int $per_page      Results per page. Default 20.
     * @type int $page          Current page. Default 1.
     *           }
     *
     * @return array {
     *
     * @type array $items        Array of ticket objects.
     * @type int $total        Total matching tickets.
     * @type int $per_page     Results per page.
     * @type int $current_page Current page number.
     *           }
     */
    public static function all(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ['t.deleted_at IS NULL'];
        $values = [];
        $join = '';
        $group_by = '';

        // Status filter.
        if (! empty($filters['status'])) {
            $where[] = 't.status = %s';
            $values[] = $filters['status'];
        }

        // Priority filter.
        if (! empty($filters['priority'])) {
            $where[] = 't.priority = %s';
            $values[] = $filters['priority'];
        }

        // Ticket type filter.
        if (! empty($filters['ticket_type'])) {
            $where[] = 't.ticket_type = %s';
            $values[] = $filters['ticket_type'];
        }

        // Assigned to filter.
        if (! empty($filters['assigned_to'])) {
            $where[] = 't.assigned_to = %d';
            $values[] = (int) $filters['assigned_to'];
        }

        // Unassigned filter.
        if (! empty($filters['unassigned'])) {
            $where[] = 't.assigned_to IS NULL';
        }

        // Department filter.
        if (! empty($filters['department_id'])) {
            $where[] = 't.department_id = %d';
            $values[] = (int) $filters['department_id'];
        }

        // Search filter.
        if (! empty($filters['search'])) {
            $like = '%'.$wpdb->esc_like($filters['search']).'%';
            $where[] = '(t.subject LIKE %s OR t.reference LIKE %s OR t.description LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        // SLA breached filter.
        if (isset($filters['sla_breached']) && $filters['sla_breached']) {
            $where[] = '(t.sla_first_response_breached = 1 OR t.sla_resolution_breached = 1)';
        }

        // Requester filter.
        if (! empty($filters['requester_id'])) {
            $where[] = 't.requester_id = %d';
            $values[] = (int) $filters['requester_id'];
        }

        // Tag filter (join with pivot table).
        if (! empty($filters['tag_ids']) && is_array($filters['tag_ids'])) {
            $tag_table = Escalated::table('ticket_tag');
            $placeholders = implode(',', array_fill(0, count($filters['tag_ids']), '%d'));
            $join = " INNER JOIN {$tag_table} AS tt ON tt.ticket_id = t.id";
            $where[] = "tt.tag_id IN ({$placeholders})";
            $values = array_merge($values, array_map('intval', $filters['tag_ids']));
            $group_by = ' GROUP BY t.id';
        }

        // Sorting.
        $allowed_sort = ['created_at', 'updated_at', 'priority', 'status', 'subject', 'id'];
        $sort_by = isset($filters['sort_by']) && in_array($filters['sort_by'], $allowed_sort, true)
            ? $filters['sort_by']
            : 'created_at';
        $sort_dir = isset($filters['sort_dir']) && strtoupper($filters['sort_dir']) === 'ASC'
            ? 'ASC'
            : 'DESC';

        // Pagination.
        $per_page = isset($filters['per_page']) ? absint($filters['per_page']) : 20;
        $page = isset($filters['page']) ? max(1, absint($filters['page'])) : 1;
        $offset = ($page - 1) * $per_page;

        $where_clause = implode(' AND ', $where);

        // Count total.
        $count_sql = "SELECT COUNT(DISTINCT t.id) FROM {$table} AS t{$join} WHERE {$where_clause}";
        if (! empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, $values);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Fetch items.
        $sql = "SELECT t.* FROM {$table} AS t{$join} WHERE {$where_clause}{$group_by} ORDER BY t.{$sort_by} {$sort_dir} LIMIT %d OFFSET %d";
        $query_values = array_merge($values, [$per_page, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($sql, $query_values));

        return [
            'items' => $items ?: [],
            'total' => $total,
            'per_page' => $per_page,
            'current_page' => $page,
        ];
    }

    /**
     * Generate a unique ticket reference.
     *
     * Format: PREFIX-00001 (e.g. ESC-00001).
     *
     * @return string
     */
    public static function generate_reference()
    {
        global $wpdb;
        $table = static::table();
        $prefix = Setting::get('ticket_reference_prefix', 'ESC');

        $max_id = (int) $wpdb->get_var("SELECT MAX(id) FROM {$table}");
        $next = $max_id + 1;

        return $prefix.'-'.str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Check if a status represents an open ticket.
     *
     * @param  string  $status
     * @return bool
     */
    public static function is_open($status)
    {
        return ! in_array($status, ['resolved', 'closed'], true);
    }

    /**
     * Get a WHERE clause fragment for open tickets.
     *
     * @return string
     */
    public static function scope_open()
    {
        return "status NOT IN ('resolved', 'closed')";
    }

    /**
     * Count tickets grouped by status.
     *
     * @return array Associative array of status => count.
     */
    public static function count_by_status()
    {
        global $wpdb;
        $table = static::table();

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS count FROM {$table} WHERE deleted_at IS NULL GROUP BY status"
        );

        $counts = [];
        if ($rows) {
            foreach ($rows as $row) {
                $counts[$row->status] = (int) $row->count;
            }
        }

        return $counts;
    }

    /**
     * Count open tickets assigned to a specific agent.
     *
     * @param  int  $user_id
     * @return int
     */
    public static function count_for_agent($user_id)
    {
        global $wpdb;
        $table = static::table();
        $scope = static::scope_open();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE assigned_to = %d AND {$scope} AND deleted_at IS NULL",
                $user_id
            )
        );
    }

    /**
     * Add computed properties to a ticket object for API serialization.
     *
     * Adds: requester_name, requester_email, last_reply_at,
     * last_reply_author, is_live_chat, is_snoozed, chat_session_id,
     * chat_started_at, chat_messages, chat_metadata,
     * requester_ticket_count, related_tickets.
     *
     * @param  object  $ticket  A raw ticket row from the database.
     * @return object The same ticket object with computed fields appended.
     */
    public static function enrich(object $ticket): object
    {
        global $wpdb;

        // requester_name / requester_email.
        $requester_name = null;
        $requester_email = null;

        if (! empty($ticket->requester_id)) {
            $user = get_userdata((int) $ticket->requester_id);
            if ($user) {
                $requester_name = $user->display_name;
                $requester_email = $user->user_email;
            }
        }

        $ticket->requester_name = $requester_name
            ?? ($ticket->guest_name ?? null)
            ?? ($ticket->guest_email ?? null);
        $ticket->requester_email = $requester_email
            ?? ($ticket->guest_email ?? null);

        // last_reply_at / last_reply_author.
        $reply_table = Escalated::table('replies');
        $last_reply = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT created_at, author_id FROM {$reply_table} WHERE ticket_id = %d AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1",
                $ticket->id
            )
        );

        $ticket->last_reply_at = $last_reply ? $last_reply->created_at : null;
        $ticket->last_reply_author = null;

        if ($last_reply && ! empty($last_reply->author_id)) {
            $author = get_userdata((int) $last_reply->author_id);
            $ticket->last_reply_author = $author ? $author->display_name : null;
        }

        // is_live_chat.
        $ticket->is_live_chat = ($ticket->status ?? '') === 'live'
            && ($ticket->channel ?? '') === 'chat';

        // is_snoozed.
        $snooze_service = new TicketSnoozeService;
        $snoozed_until = $snooze_service->get_snooze_until((int) $ticket->id);
        $ticket->is_snoozed = ! empty($snoozed_until) && strtotime($snoozed_until) > time();

        // Chat session context.
        $chat_session = ChatSession::find_by_ticket_id((int) $ticket->id);
        $ticket->chat_session_id = $chat_session ? (int) $chat_session->id : null;
        $ticket->chat_started_at = $chat_session ? $chat_session->created_at : null;
        $ticket->chat_metadata = $chat_session && ! empty($chat_session->metadata)
            ? json_decode($chat_session->metadata, true)
            : null;

        // Chat messages (replies on the linked ticket).
        if ($chat_session) {
            $chat_replies = Reply::for_ticket((int) $ticket->id, false);
            $ticket->chat_messages = array_map(function ($reply) {
                return [
                    'id' => (int) $reply->id,
                    'body' => $reply->body,
                    'author_id' => $reply->author_id ? (int) $reply->author_id : null,
                    'created_at' => $reply->created_at,
                ];
            }, $chat_replies);
        } else {
            $ticket->chat_messages = [];
        }

        // Requester ticket count.
        $ticket->requester_ticket_count = 0;
        if (! empty($ticket->requester_id)) {
            $tickets_table = static::table();
            $ticket->requester_ticket_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tickets_table} WHERE requester_id = %d AND deleted_at IS NULL",
                    (int) $ticket->requester_id
                )
            );
        }

        // Related (linked) tickets.
        $linked = TicketSplitService::get_linked_tickets((int) $ticket->id);
        $ticket->related_tickets = array_map(function ($link) {
            return [
                'id' => (int) $link->id,
                'reference' => $link->reference,
                'subject' => $link->subject,
                'status' => $link->status,
                'link_type' => $link->link_type ?? 'related',
            ];
        }, $linked);

        return $ticket;
    }

    /**
     * Add computed properties to an array of ticket objects.
     *
     * @param  array  $tickets  Array of ticket row objects.
     * @return array The same array with each ticket enriched.
     */
    public static function enrich_many(array $tickets): array
    {
        return array_map([static::class, 'enrich'], $tickets);
    }

    /**
     * Tag IDs linked to a ticket (pivot escalated_ticket_tag).
     *
     * @return int[]
     */
    public static function tag_ids(int $ticket_id): array
    {
        global $wpdb;
        $pivot = Escalated::table('ticket_tag');
        $ids = $wpdb->get_col(
            $wpdb->prepare("SELECT tag_id FROM {$pivot} WHERE ticket_id = %d", $ticket_id)
        );

        return $ids ? array_map('intval', $ids) : [];
    }
}
