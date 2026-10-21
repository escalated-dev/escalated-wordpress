<?php

namespace Escalated\Models;

use Escalated\Escalated;
use Escalated\Services\EventVocabulary;

/**
 * An outbound webhook subscription: a URL, an optional signing secret, the
 * events it receives and whether it is active.
 */
class Webhook
{
    public static function table(): string
    {
        return Escalated::table('webhooks');
    }

    public static function find($id): ?object
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)) ?: null;
    }

    /**
     * @return array<int, object>
     */
    public static function all(): array
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC") ?: [];
    }

    /**
     * @return array<int, object>
     */
    public static function active(): array
    {
        $wpdb = Escalated::db();
        $table = static::table();

        return $wpdb->get_results("SELECT * FROM {$table} WHERE active = 1 ORDER BY id ASC") ?: [];
    }

    /**
     * @param  array{url: string, events: array, secret?: ?string, active?: bool|int}  $data
     * @return int|false
     */
    public static function create(array $data)
    {
        $wpdb = Escalated::db();
        $now = current_time('mysql');

        $result = $wpdb->insert(static::table(), [
            'url' => (string) $data['url'],
            'events' => wp_json_encode(EventVocabulary::canonical_list((array) $data['events'])),
            'secret' => ($data['secret'] ?? '') !== '' ? (string) $data['secret'] : null,
            'active' => ! empty($data['active'] ?? true) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    public static function update($id, array $data): bool
    {
        $wpdb = Escalated::db();
        $row = [];

        if (array_key_exists('url', $data)) {
            $row['url'] = (string) $data['url'];
        }
        if (array_key_exists('events', $data)) {
            $row['events'] = wp_json_encode(EventVocabulary::canonical_list((array) $data['events']));
        }
        if (array_key_exists('secret', $data)) {
            $row['secret'] = ($data['secret'] ?? '') !== '' ? (string) $data['secret'] : null;
        }
        if (array_key_exists('active', $data)) {
            $row['active'] = ! empty($data['active']) ? 1 : 0;
        }
        $row['updated_at'] = current_time('mysql');

        return $wpdb->update(static::table(), $row, ['id' => (int) $id]) !== false;
    }

    /**
     * Delete the webhook and its delivery log.
     */
    public static function delete($id): bool
    {
        $wpdb = Escalated::db();
        $wpdb->delete(WebhookDelivery::table(), ['webhook_id' => (int) $id]);

        return $wpdb->delete(static::table(), ['id' => (int) $id]) !== false;
    }

    /**
     * The events the webhook is subscribed to, with legacy names read as
     * their canonical names (ADR 2026-10-21-event-vocabulary).
     *
     * @return list<string>
     */
    public static function subscribed_events(object $webhook): array
    {
        $events = is_string($webhook->events ?? null) ? json_decode($webhook->events, true) : ($webhook->events ?? []);

        return EventVocabulary::canonical_list(is_array($events) ? $events : []);
    }

    public static function subscribed_to(object $webhook, string $event): bool
    {
        return in_array(EventVocabulary::canonical($event), self::subscribed_events($webhook), true);
    }
}
