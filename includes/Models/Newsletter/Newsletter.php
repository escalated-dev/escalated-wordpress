<?php

namespace Escalated\Models\Newsletter;

use Escalated\Escalated;

/**
 * Static wrapper around the {prefix}newsletters table. Mirrors the WP
 * convention used by other Escalated Models — static helpers around $wpdb.
 */
class Newsletter
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'paused', 'failed'];

    public static function table(): string
    {
        return Escalated::table('newsletters');
    }

    public static function find(int $id): ?object
    {
        $wpdb = \Escalated\Escalated::db();

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id = %d', $id)) ?: null;
    }

    public static function create(array $attrs): int
    {
        $wpdb = \Escalated\Escalated::db();
        $now = current_time('mysql');
        $wpdb->insert(
            self::table(),
            array_merge(['status' => 'draft', 'created_at' => $now, 'updated_at' => $now], $attrs)
        );

        return (int) $wpdb->insert_id;
    }

    public static function update(int $id, array $attrs): void
    {
        $wpdb = \Escalated\Escalated::db();
        $wpdb->update(self::table(), array_merge($attrs, ['updated_at' => current_time('mysql')]), ['id' => $id]);
    }
}
