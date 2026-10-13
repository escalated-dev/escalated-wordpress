<?php

namespace Escalated\Services;

use Escalated\Escalated;

final class TicketRoutingConditions
{
    /** Prepared SQL for the shared type/account condition vocabulary. */
    public static function sql(array $condition, string $alias = 't'): string
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        $negative = in_array($operator, ['!=', '<>', 'not_equals'], true);
        if (! $negative && ! in_array($operator, ['=', '==', 'equals'], true)) {
            return '1=0';
        }
        $db = Escalated::db();
        if ($field === 'account') {
            $account = TicketAccounts::identity($value);
            if ($account === null) {
                return '1=0';
            }
            $sql = $db->prepare(
                "CAST({$alias}.account_type AS BINARY) = CAST(%s AS BINARY) AND CAST({$alias}.account_id AS BINARY) = CAST(%s AS BINARY)",
                $account['type'], $account['id']
            );
        } elseif (in_array($field, ['account_type', 'account_id', 'ticket_type'], true) && (is_string($value) || is_int($value))) {
            $sql = $db->prepare("CAST({$alias}.{$field} AS BINARY) = CAST(%s AS BINARY)", (string) $value);
        } else {
            return '1=0';
        }

        return ($negative ? 'NOT ' : '').'('.$sql.')';
    }
}
