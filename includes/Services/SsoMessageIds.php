<?php

namespace Escalated\Services;

/**
 * Single-use message IDs for SSO: the AuthnRequest IDs this site issued
 * (`saml_request`), and the SAML assertion IDs (`saml_assertion`) and JWT
 * `jti`s (`jwt`) it has accepted. Rows expire at `expires_at` (Unix seconds)
 * and are pruned on write.
 */
class SsoMessageIds
{
    /** Store an ID that a later message must name (an issued request). */
    public function remember(string $kind, string $id, int $expires_at): void
    {
        $this->prune();
        \Escalated\Escalated::db()->insert(self::table(), ['kind' => $kind, 'message_id' => $id, 'expires_at' => $expires_at]);
    }

    /** Remove an unexpired remembered ID: true only the first time. */
    public function consume(string $kind, string $id): bool
    {
        $wpdb = \Escalated\Escalated::db();
        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM '.self::table().' WHERE kind = %s AND message_id = %s AND expires_at > %d',
            $kind,
            $id,
            time()
        ));

        return $deleted === 1;
    }

    /** Record an ID as used: true the first time, false on a replay. */
    public function claim(string $kind, string $id, int $expires_at): bool
    {
        $this->prune();
        $wpdb = \Escalated\Escalated::db();
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(self::table(), ['kind' => $kind, 'message_id' => $id, 'expires_at' => $expires_at]);
        $wpdb->suppress_errors($suppress);

        // The unique index on kind + message_id refuses the second insert.
        return $inserted === 1;
    }

    private function prune(): void
    {
        $wpdb = \Escalated\Escalated::db();
        $wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE expires_at <= %d', time()));
    }

    private static function table(): string
    {
        return \Escalated\Escalated::table('sso_message_ids');
    }
}
