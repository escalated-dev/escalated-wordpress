<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Models\Setting;

final class TicketAccounts
{
    /** Opaque identities: preserve case, whitespace and leading zeroes. */
    public static function identity($value): ?array
    {
        if (! is_array($value) || ! isset($value['type'], $value['id'])
            || ! is_string($value['type']) || (! is_string($value['id']) && ! is_int($value['id']))) {
            return null;
        }
        $type = $value['type'];
        $id = (string) $value['id'];
        if ($type === '' || $id === '' || mb_strlen($type) > 191 || mb_strlen($id) > 191) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }

    public static function for_requester(array $data): array
    {
        if (! Setting::get_bool('accounts_enabled') || isset($data['account_type']) || isset($data['account_id'])) {
            return $data;
        }
        $account = self::identity(apply_filters('escalated_ticket_account', null, $data, null));

        return $account ? array_merge($data, ['account_type' => $account['type'], 'account_id' => $account['id']]) : $data;
    }

    public static function from_subject(int $ticket_id, string $type, string $id): void
    {
        if (! Setting::get_bool('accounts_enabled')) {
            return;
        }
        $ticket = TicketAccess::require_ticket($ticket_id);
        if ($ticket->account_type !== null || $ticket->account_id !== null) {
            return;
        }
        $account = self::identity(apply_filters('escalated_ticket_account', null, (array) $ticket, ['type' => $type, 'id' => $id]));
        if ($account === null) {
            return;
        }
        $db = Escalated::db();
        $table = Escalated::table('tickets');
        $db->query($db->prepare(
            "UPDATE `{$table}` SET account_type = %s, account_id = %s WHERE id = %d AND account_type IS NULL AND account_id IS NULL",
            $account['type'], $account['id'], $ticket_id
        ));
    }
}
