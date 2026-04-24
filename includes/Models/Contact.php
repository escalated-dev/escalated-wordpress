<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * First-class identity for guest requesters (Pattern B).
 *
 * Deduped by email (unique index, case-insensitively normalized
 * before write). Links to a WordPress user via `user_id` once the
 * guest accepts a signup invite.
 *
 * Coexists with the inline `guest_name` / `guest_email` /
 * `guest_token` columns on Ticket for backwards compatibility —
 * a follow-up pass backfills `contact_id` from `guest_email`.
 * New code should resolve contacts via
 * {@see Contact::find_or_create_by_email}.
 *
 * @see https://github.com/escalated-dev/escalated-nestjs/pull/17 reference impl
 */
class Contact
{
    /**
     * @return string
     */
    public static function table()
    {
        return Escalated::table('contacts');
    }

    /**
     * Canonical email normalization: trim + lowercase. Always call
     * on any caller-supplied email before inserting or looking up.
     *
     * @param  string|null  $email
     * @return string
     */
    public static function normalize_email($email)
    {
        if (! is_string($email)) {
            return '';
        }
        return strtolower(trim($email));
    }

    /**
     * Decide what {@see find_or_create_by_email} should do given the
     * lookup result and incoming name. Pure function — testable
     * without touching the database.
     *
     * @param  object|null  $existing   Row from wpdb->get_row or null
     * @param  string|null  $incoming_name
     * @return string  One of 'create', 'update-name', 'return-existing'
     */
    public static function decide_action($existing, $incoming_name)
    {
        if ($existing === null) {
            return 'create';
        }
        $existing_name = isset($existing->name) ? $existing->name : null;
        if ((is_null($existing_name) || $existing_name === '') && ! empty($incoming_name)) {
            return 'update-name';
        }
        return 'return-existing';
    }

    /**
     * @param  int  $id
     * @return object|null
     */
    public static function find($id)
    {
        global $wpdb;
        $table = static::table();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * @param  string  $email
     * @return object|null
     */
    public static function find_by_email($email)
    {
        global $wpdb;
        $table = static::table();
        $normalized = static::normalize_email($email);
        if ($normalized === '') {
            return null;
        }
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE email = %s", $normalized)
        );
    }

    /**
     * Dedupe-on-write: returns the existing Contact row for a
     * previously-seen email, or creates a new one. If the existing
     * row has a blank name and a non-blank name is supplied,
     * the existing row is updated in place.
     *
     * @param  string  $email
     * @param  string|null  $name
     * @return object  Row from wpdb->get_row
     */
    public static function find_or_create_by_email($email, $name = null)
    {
        global $wpdb;
        $table = static::table();
        $normalized = static::normalize_email($email);

        $existing = static::find_by_email($normalized);
        $action = static::decide_action($existing, $name);

        if ($action === 'return-existing') {
            return $existing;
        }

        $now = current_time('mysql');

        if ($action === 'update-name') {
            $wpdb->update(
                $table,
                ['name' => $name, 'updated_at' => $now],
                ['id' => $existing->id]
            );
            return static::find($existing->id);
        }

        // action === 'create'
        $wpdb->insert($table, [
            'email' => $normalized,
            'name' => $name ?: null,
            'user_id' => null,
            'metadata' => wp_json_encode(new \stdClass()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return static::find((int) $wpdb->insert_id);
    }

    /**
     * Link a Contact to a WP user id.
     *
     * @param  int  $contact_id
     * @param  int  $user_id
     * @return object|null
     */
    public static function link_to_user($contact_id, $user_id)
    {
        global $wpdb;
        $table = static::table();
        $wpdb->update(
            $table,
            ['user_id' => $user_id, 'updated_at' => current_time('mysql')],
            ['id' => $contact_id]
        );
        return static::find($contact_id);
    }

    /**
     * Link + back-stamp requester_id on all prior tickets owned
     * by this contact. Called when a guest accepts the signup invite.
     *
     * @param  int  $contact_id
     * @param  int  $user_id
     * @return object|null
     */
    public static function promote_to_user($contact_id, $user_id)
    {
        global $wpdb;
        $contact = static::link_to_user($contact_id, $user_id);
        $tickets_table = Ticket::table();
        $wpdb->update(
            $tickets_table,
            ['requester_id' => $user_id, 'updated_at' => current_time('mysql')],
            ['contact_id' => $contact_id]
        );
        return $contact;
    }
}
