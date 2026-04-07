<?php

namespace Escalated\Models;

use Escalated\Escalated;

class ApiToken
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('api_tokens');
    }

    /**
     * Find an API token record by ID.
     *
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
     * Create a new API token record.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        // Encode abilities if passed as an array.
        if (isset($data['abilities']) && is_array($data['abilities'])) {
            $data['abilities'] = wp_json_encode($data['abilities']);
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update an API token record.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        // Encode abilities if passed as an array.
        if (isset($data['abilities']) && is_array($data['abilities'])) {
            $data['abilities'] = wp_json_encode($data['abilities']);
        }

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete an API token record.
     *
     * @param  int  $id
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * Get all API tokens with optional filters.
     *
     * @return array
     */
    public static function all(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ['1=1'];
        $values = [];

        if (! empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = (int) $filters['user_id'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Generate and store a new API token for a user.
     *
     * @param  int  $user_id
     * @param  string  $name  Human-readable token name.
     * @param  array  $abilities  Allowed abilities. Default ['*'] for all.
     * @return array|false {
     *
     * @type string $token  The plain-text token (only available at creation time).
     * @type object $record The stored database record.
     *              } or false on failure.
     */
    public static function create_token($user_id, $name, $abilities = ['*'])
    {
        // Generate a random 64-character token.
        $plain_token = bin2hex(random_bytes(32));

        // Store the SHA-256 hash.
        $token_hash = hash('sha256', $plain_token);

        $id = static::create([
            'user_id' => (int) $user_id,
            'name' => $name,
            'token' => $token_hash,
            'abilities' => $abilities,
        ]);

        if ($id === false) {
            return false;
        }

        $record = static::find($id);

        return [
            'token' => $plain_token,
            'record' => $record,
        ];
    }

    /**
     * Find an API token record by plain-text token.
     *
     * Hashes the token with SHA-256 and looks up the hash.
     *
     * @param  string  $plain_token
     * @return object|null
     */
    public static function find_by_token($plain_token)
    {
        global $wpdb;
        $table = static::table();
        $token_hash = hash('sha256', $plain_token);

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE token = %s", $token_hash)
        );
    }

    /**
     * Check if a token record has a specific ability.
     *
     * @param  object  $record  The API token record.
     * @param  string  $ability  The ability to check for.
     * @return bool
     */
    public static function has_ability($record, $ability)
    {
        $abilities = json_decode($record->abilities, true);

        if (! is_array($abilities)) {
            return false;
        }

        // Wildcard means all abilities are granted.
        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    /**
     * Update the last used timestamp and IP for a token.
     *
     * @param  int  $id
     * @param  string  $ip  Client IP address.
     * @return bool
     */
    public static function update_last_used($id, $ip)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->update(
            $table,
            [
                'last_used_at' => current_time('mysql'),
                'last_used_ip' => $ip,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Get all tokens for a specific user.
     *
     * @param  int  $user_id
     * @return array
     */
    public static function for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            )
        ) ?: [];
    }

    /**
     * Delete all expired tokens.
     *
     * @return int|false Number of deleted rows or false on error.
     */
    public static function delete_expired()
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",
                $now
            )
        );
    }
}
