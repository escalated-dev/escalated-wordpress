<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * One event queued for one webhook, and the outcome of sending it.
 *
 * status: pending (waiting for its first or next attempt), sending (claimed by
 * a worker), delivered (2xx), failed (attempts used up or refused by the URL
 * guard). Times in this table are UTC.
 */
class WebhookDelivery
{
    public const MAX_ATTEMPTS = 3;

    public static function table(): string
    {
        return Escalated::table('webhook_deliveries');
    }

    public static function find($id): ?object
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }

    /**
     * @return int|false
     */
    public static function create(int $webhook_id, string $event, array $payload, ?string $occurred_at = null)
    {
        $wpdb = Escalated::db();
        $now = gmdate('Y-m-d H:i:s');

        $result = $wpdb->insert(static::table(), [
            'webhook_id' => $webhook_id,
            'event' => $event,
            'payload' => wp_json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'occurred_at' => $occurred_at ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    public static function update($id, array $data): bool
    {
        $wpdb = Escalated::db();
        $data['updated_at'] = gmdate('Y-m-d H:i:s');

        return $wpdb->update(static::table(), $data, ['id' => (int) $id]) !== false;
    }

    /**
     * @return array<int, object>
     */
    public static function for_webhook(int $webhook_id, int $limit = 50, int $offset = 0): array
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE webhook_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $webhook_id,
            $limit,
            $offset
        )) ?: [];
    }

    public static function count_for_webhook(int $webhook_id): int
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE webhook_id = %d", $webhook_id));
    }

    public static function latest_for_webhook(int $webhook_id): ?object
    {
        return self::for_webhook($webhook_id, 1)[0] ?? null;
    }

    /**
     * Deliveries whose next attempt is due, plus any claimed more than ten
     * minutes ago by a worker that died.
     *
     * @return array<int, object>
     */
    public static function due(int $limit = 25): array
    {
        $wpdb = Escalated::db();
        $table = static::table();
        $now = gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE (status = 'pending' AND next_attempt_at <= %s)
                OR (status = 'sending' AND updated_at <= %s)
             ORDER BY next_attempt_at ASC, id ASC
             LIMIT %d",
            $now,
            $stale,
            $limit
        )) ?: [];
    }

    /**
     * Claim a delivery for sending. Only one worker wins.
     */
    public static function claim(object $delivery): bool
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'sending', updated_at = %s WHERE id = %d AND status = %s AND updated_at = %s",
            gmdate('Y-m-d H:i:s'),
            (int) $delivery->id,
            $delivery->status,
            $delivery->updated_at
        )) === 1;
    }
}
