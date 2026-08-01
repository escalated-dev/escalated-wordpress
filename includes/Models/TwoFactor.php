<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * Per-user two-factor authentication record.
 *
 * Stored in the `escalated_two_factors` table (one row per user, keyed
 * unique on user_id), mirroring how {@see ApiToken} models the
 * `escalated_api_tokens` table with static $wpdb helpers.
 *
 * At-rest protection:
 *   - `secret` is reversibly encrypted (AES-256-CBC keyed off the site
 *     salts) because the raw base32 secret is required to compute TOTP
 *     codes on every verification.
 *   - `recovery_codes` is a JSON array of SHA-256 hashes — one-way, like
 *     ApiToken hashes its tokens. Plain codes are surfaced to the user
 *     exactly once at generation time and can never be read back.
 */
class TwoFactor
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('two_factors');
    }

    /**
     * Find a record by ID.
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
     * Get the (single) two-factor record for a user, if any.
     *
     * @param  int  $user_id
     * @return object|null
     */
    public static function for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", (int) $user_id)
        );
    }

    /**
     * Get the pending (not-yet-confirmed) record for a user, if any.
     *
     * @param  int  $user_id
     * @return object|null
     */
    public static function pending_for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND confirmed_at IS NULL",
                (int) $user_id
            )
        );
    }

    /**
     * Get the confirmed (fully enabled) record for a user, if any.
     *
     * @param  int  $user_id
     * @return object|null
     */
    public static function confirmed_for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND confirmed_at IS NOT NULL",
                (int) $user_id
            )
        );
    }

    /**
     * Create a two-factor record.
     *
     * Encrypts `secret` and hashes any `recovery_codes` array before
     * persisting.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        if (isset($data['secret'])) {
            $data['secret'] = static::encrypt((string) $data['secret']);
        }

        if (isset($data['recovery_codes']) && is_array($data['recovery_codes'])) {
            $data['recovery_codes'] = static::encode_recovery_codes($data['recovery_codes']);
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update a record. Encrypts/hashes secret + recovery_codes when present.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        if (isset($data['secret'])) {
            $data['secret'] = static::encrypt((string) $data['secret']);
        }

        if (isset($data['recovery_codes']) && is_array($data['recovery_codes'])) {
            $data['recovery_codes'] = static::encode_recovery_codes($data['recovery_codes']);
        }

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => (int) $id]) !== false;
    }

    /**
     * Mark a record confirmed (2FA fully enabled).
     *
     * @param  int  $id
     * @return bool
     */
    public static function confirm($id)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        return $wpdb->update(
            $table,
            ['confirmed_at' => $now, 'updated_at' => $now],
            ['id' => (int) $id]
        ) !== false;
    }

    /**
     * Delete a record by ID.
     *
     * @param  int  $id
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['id' => (int) $id]) !== false;
    }

    /**
     * Delete all records for a user.
     *
     * @param  int  $user_id
     * @return bool
     */
    public static function delete_for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['user_id' => (int) $user_id]) !== false;
    }

    /**
     * Delete only the pending (unconfirmed) record for a user.
     *
     * @param  int  $user_id
     * @return bool
     */
    public static function delete_pending_for_user($user_id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND confirmed_at IS NULL",
                (int) $user_id
            )
        ) !== false;
    }

    /**
     * Whether the given record is confirmed.
     *
     * @param  object|null  $record
     * @return bool
     */
    public static function is_confirmed($record)
    {
        return $record !== null && ! empty($record->confirmed_at);
    }

    /**
     * Decrypt and return the raw base32 secret from a record.
     *
     * @param  object  $record
     * @return string
     */
    public static function secret_of($record)
    {
        return static::decrypt((string) $record->secret);
    }

    /**
     * Consume a single-use recovery code for a record.
     *
     * If the code matches a stored hash it is removed from the set and the
     * record persisted, returning true. Otherwise returns false and the set
     * is left untouched.
     *
     * @param  int  $id
     * @param  string  $plain_code
     * @return bool
     */
    public static function consume_recovery_code($id, $plain_code)
    {
        $record = static::find($id);

        if (! $record) {
            return false;
        }

        $hashes = static::decode_recovery_hashes($record->recovery_codes);
        $target = static::hash_recovery_code($plain_code);

        $index = array_search($target, $hashes, true);

        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);

        global $wpdb;

        $wpdb->update(
            static::table(),
            [
                'recovery_codes' => wp_json_encode(array_values($hashes)),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => (int) $id]
        );

        return true;
    }

    /**
     * Number of unused recovery codes remaining on a record.
     *
     * @param  object  $record
     * @return int
     */
    public static function remaining_recovery_codes($record)
    {
        return count(static::decode_recovery_hashes($record->recovery_codes));
    }

    /**
     * Encode a list of plain recovery codes into a JSON array of hashes.
     *
     * @param  string[]  $codes
     * @return string
     */
    protected static function encode_recovery_codes(array $codes)
    {
        $hashes = array_map([static::class, 'hash_recovery_code'], $codes);

        return wp_json_encode(array_values($hashes));
    }

    /**
     * Decode the stored recovery_codes column into an array of hashes.
     *
     * @param  string|null  $stored
     * @return string[]
     */
    protected static function decode_recovery_hashes($stored)
    {
        if (empty($stored)) {
            return [];
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Hash a recovery code for storage/comparison (case-insensitive).
     *
     * @param  string  $code
     * @return string
     */
    public static function hash_recovery_code($code)
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    /**
     * Encrypt a value for at-rest storage.
     *
     * @return string Base64(iv . ciphertext).
     */
    public static function encrypt(string $plaintext): string
    {
        $key = static::encryption_key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return '';
        }

        return base64_encode($iv.$cipher);
    }

    /**
     * Decrypt a value produced by {@see encrypt()}.
     */
    public static function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }

        $key = static::encryption_key();
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? '' : $plain;
    }

    /**
     * Derive a stable 32-byte encryption key from the WordPress salts.
     */
    protected static function encryption_key(): string
    {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'escalated-fallback-salt';

        return hash('sha256', $salt.'|escalated_two_factor', true);
    }
}
