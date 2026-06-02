<?php

namespace Escalated\Models\Newsletter;

use Escalated\Escalated;

class NewsletterTemplate
{
    public static function table(): string
    {
        return Escalated::table('newsletter_templates');
    }

    public static function find(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id = %d', $id)) ?: null;
    }
}
