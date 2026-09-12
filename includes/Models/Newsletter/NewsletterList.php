<?php

namespace Escalated\Models\Newsletter;

use Escalated\Escalated;

class NewsletterList
{
    public const KIND_STATIC = 'static';

    public const KIND_DYNAMIC = 'dynamic';

    public static function table(): string
    {
        return Escalated::table('newsletter_lists');
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
            array_merge(['created_at' => $now, 'updated_at' => $now], $attrs)
        );

        return (int) $wpdb->insert_id;
    }
}
