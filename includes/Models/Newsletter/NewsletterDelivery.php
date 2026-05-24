<?php

namespace Escalated\Models\Newsletter;

use Escalated\Escalated;

class NewsletterDelivery
{
    public const STATUSES = ['pending', 'queued', 'sent', 'bounced', 'complained', 'suppressed', 'failed'];

    public static function table(): string
    {
        return Escalated::table('newsletter_deliveries');
    }

    public static function find_by_token(string $token): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.self::table().' WHERE tracking_token = %s',
            $token
        )) ?: null;
    }
}
