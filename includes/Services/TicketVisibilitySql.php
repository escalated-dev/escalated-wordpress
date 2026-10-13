<?php

namespace Escalated\Services;

use Escalated\Escalated;

/**
 * Scope the SQL used by wpdb, including aggregate/report and child queries.
 * A lexer keeps identifiers separate from literals/comments. Unsupported SQL
 * touching protected tables fails closed; trusted maintenance has an explicit
 * TicketAccess::without_restriction() escape hatch.
 */
final class TicketVisibilitySql
{
    private const CHILDREN = ['replies', 'ticket_tag', 'ticket_activities', 'ticket_followers',
        'satisfaction_ratings', 'workflow_logs', 'chat_sessions', 'deferred_workflow_jobs',
        'ticket_subjects', 'side_conversations', 'ticket_links', 'inbound_emails'];

    private const RESERVED = ['WHERE', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'UNION', 'ON',
        'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN',
        'FOR', 'LOCK', 'INTO', 'SET', 'VALUES', 'USE', 'IGNORE', 'FORCE', 'PARTITION', 'AS'];

    public static function filter(string $sql): string
    {
        // Do not resolve settings/user state for WordPress or configuration SQL.
        if (stripos($sql, 'escalated_') === false) {
            return $sql;
        }
        $prefix = Escalated::db()->prefix.'escalated_';
        if (stripos($sql, $prefix) === false) {
            return $sql;
        }
        $tables = [];
        foreach (array_merge(['tickets', 'attachments', 'side_conversation_replies', 'audit_logs'], self::CHILDREN) as $name) {
            $tables[strtolower($prefix.$name)] = $name;
        }
        $tokens = self::tokens($sql);
        $protected = [];
        foreach ($tokens as $i => $token) {
            if ($token['kind'] === 'identifier' && isset($tables[strtolower($token['value'])])) {
                $protected[$i] = $tables[strtolower($token['value'])];
            }
        }
        if (($protected === [] && ! str_contains($sql, '/*!')) || ! TicketAccess::restricted()) {
            return $sql;
        }
        // Executable comments and multiple statements are outside our grammar.
        if (str_contains($sql, '/*!')) {
            self::deny();
        }
        foreach ($tokens as $i => $token) {
            if ($token['text'] === ';' && $i !== count($tokens) - 1) {
                self::deny();
            }
        }
        if ($protected === []) {
            return $sql;
        }
        $operation = strtoupper($tokens[0]['value']);
        if ($operation === 'SELECT') {
            $replacements = [];
            foreach ($protected as $i => $name) {
                $previous = strtoupper($tokens[$i - 1]['value'] ?? '');
                $next = $tokens[$i + 1] ?? null;
                // Qualified column references retain the original table alias.
                if (($next['text'] ?? '') === '.') {
                    continue;
                }
                if (! in_array($previous, ['FROM', 'JOIN', 'STRAIGHT_JOIN'], true)) {
                    self::deny();
                }
                $alias = '';
                if ($next && (strtoupper($next['value']) === 'AS'
                    || ($next['kind'] === 'identifier' && ! in_array(strtoupper($next['value']), self::RESERVED, true)))) {
                    // Leave an explicit alias in the original SQL.
                } else {
                    $alias = ' AS '.$tokens[$i]['text'];
                }
                $table = $tokens[$i]['text'];
                $where = self::predicate($name, 'access_row');
                $replacements[] = [$tokens[$i]['offset'], strlen($table), "(SELECT * FROM {$table} AS access_row WHERE {$where})".$alias];
            }
            foreach (array_reverse($replacements) as [$offset, $length, $replacement]) {
                $sql = substr_replace($sql, $replacement, $offset, $length);
            }

            return $sql;
        }
        if (! in_array($operation, ['UPDATE', 'DELETE', 'INSERT'], true)) {
            self::deny();
        }
        $table_index = $operation === 'UPDATE' ? 1 : ($operation === 'DELETE' ? 2 : (strtoupper($tokens[1]['value']) === 'IGNORE' ? 3 : 2));
        if (! isset($protected[$table_index]) || count($protected) !== 1) {
            self::deny();
        }
        $name = $protected[$table_index];
        $table = $tokens[$table_index]['text'];
        if ($operation === 'INSERT') {
            self::guard_insert($tokens, $table_index, $name);

            return $sql;
        }
        $where = null;
        $end = strlen($sql);
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token['text'] === '(') {
                $depth++;
            } elseif ($token['text'] === ')') {
                $depth--;
            } elseif ($depth === 0) {
                $word = strtoupper($token['value']);
                if ($word === 'WHERE') {
                    $where = $i;
                } elseif (in_array($word, ['ORDER', 'LIMIT', ';'], true)) {
                    $end = $token['offset'];
                    break;
                } elseif (in_array($word, ['JOIN', 'USING', 'SELECT'], true)) {
                    self::deny();
                }
            }
        }
        if ($operation === 'UPDATE') {
            if (strtoupper($tokens[$table_index + 1]['value'] ?? '') !== 'SET') {
                self::deny();
            }
            self::guard_update($tokens, $table_index + 2, $where ?? count($tokens), $name);
        } elseif (strtoupper($tokens[1]['value']) !== 'FROM' || ($where !== null && $where !== 3)) {
            self::deny();
        }
        $predicate = self::predicate($name, $table);
        if ($where !== null) {
            $start = $tokens[$where]['offset'] + strlen($tokens[$where]['text']);

            return substr($sql, 0, $start).' ('.substr($sql, $start, $end - $start).") AND {$predicate} ".substr($sql, $end);
        }

        return substr($sql, 0, $end)." WHERE {$predicate} ".substr($sql, $end);
    }

    private static function parent(string $expression): string
    {
        $table = Escalated::table('tickets');
        $predicate = TicketAccess::predicate('access_ticket');

        return "EXISTS (SELECT 1 FROM `{$table}` AS access_ticket WHERE access_ticket.id = {$expression} AND access_ticket.deleted_at IS NULL AND {$predicate})";
    }

    private static function predicate(string $name, string $alias): string
    {
        if ($name === 'tickets') {
            return TicketAccess::predicate($alias);
        }
        if ($name === 'attachments') {
            $replies = Escalated::table('replies');
            $ticket = self::parent("{$alias}.attachable_id");
            $reply = self::parent('access_reply.ticket_id');

            return "(({$alias}.attachable_type = 'ticket' AND {$ticket}) OR ({$alias}.attachable_type = 'reply' AND EXISTS (SELECT 1 FROM `{$replies}` access_reply WHERE access_reply.id = {$alias}.attachable_id AND {$reply})))";
        }
        if ($name === 'side_conversation_replies') {
            $parents = Escalated::table('side_conversations');
            $predicate = self::parent('access_side.ticket_id');

            return "EXISTS (SELECT 1 FROM `{$parents}` access_side WHERE access_side.id = {$alias}.side_conversation_id AND {$predicate})";
        }
        if ($name === 'audit_logs') {
            $predicate = self::parent("{$alias}.auditable_id");

            return "({$alias}.auditable_type IS NULL OR {$alias}.auditable_type <> 'Ticket' OR {$predicate})";
        }
        $predicate = self::parent("{$alias}.ticket_id");
        if ($name === 'inbound_emails') {
            $replies = Escalated::table('replies');
            $reply = self::parent('access_inbound_reply.ticket_id');

            return "({$predicate} AND ({$alias}.reply_id IS NULL OR EXISTS (SELECT 1 FROM `{$replies}` access_inbound_reply WHERE access_inbound_reply.id = {$alias}.reply_id AND {$reply})))";
        }

        return $name === 'ticket_links' ? '('.$predicate.' AND '.self::parent("{$alias}.linked_ticket_id").')' : $predicate;
    }

    private static function guard_insert(array $tokens, int $index, string $name): void
    {
        $i = $index + 1;
        if (($tokens[$i++]['text'] ?? '') !== '(') {
            self::deny();
        }
        $columns = [];
        do {
            if (($tokens[$i]['kind'] ?? '') !== 'identifier') {
                self::deny();
            }
            $columns[] = strtolower($tokens[$i++]['value']);
            $separator = $tokens[$i++]['text'] ?? '';
        } while ($separator === ',');
        if ($separator !== ')' || strtoupper($tokens[$i++]['value'] ?? '') !== 'VALUES') {
            self::deny();
        }
        do {
            if (($tokens[$i++]['text'] ?? '') !== '(') {
                self::deny();
            }
            $row = [];
            foreach ($columns as $position => $column) {
                $row[$column] = self::literal($tokens[$i++] ?? []);
                $expected = $position === count($columns) - 1 ? ')' : ',';
                if (($tokens[$i++]['text'] ?? '') !== $expected) {
                    self::deny();
                }
            }
            self::guard_row($name, $row, true);
            $separator = $tokens[$i++]['text'] ?? '';
        } while ($separator === ',');
        if (! in_array($separator, ['', ';'], true) || $i < count($tokens)) {
            self::deny();
        }
    }

    private static function guard_update(array $tokens, int $start, int $end, string $name): void
    {
        $sensitive = ['id', 'ticket_id', 'reply_id', 'linked_ticket_id', 'side_conversation_id', 'attachable_type', 'attachable_id', 'auditable_type', 'auditable_id', 'assigned_to'];
        $row = [];
        $depth = 0;
        $column = true;
        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];
            if ($column) {
                if (in_array(strtolower($token['value']), $sensitive, true)) {
                    if (($tokens[$i + 1]['text'] ?? '') !== '=' || ! in_array($tokens[$i + 3]['text'] ?? '', [',', 'WHERE', ';', ''], true)) {
                        self::deny();
                    }
                    $row[strtolower($token['value'])] = self::literal($tokens[$i + 2] ?? []);
                }
                $column = false;
            }
            $depth += $token['text'] === '(' ? 1 : ($token['text'] === ')' ? -1 : 0);
            if ($depth === 0 && $token['text'] === ',') {
                $column = true;
            }
        }
        if (isset($row['id'])) {
            self::deny();
        }
        // Assignment eligibility is checked by Ticket::update, where the entire
        // prospective ticket is available. Raw assignment SQL requires bypass.
        if ($name === 'tickets' && ! empty($row['assigned_to'])) {
            self::deny();
        }
        self::guard_row($name, $row, false);
    }

    private static function guard_row(string $name, array $row, bool $insert): void
    {
        if ($name === 'tickets') {
            if ($insert && (! TicketAccess::allows($row, TicketAccess::actor())
                || (! empty($row['assigned_to']) && ! TicketAccess::allows($row, (int) $row['assigned_to'])))) {
                self::deny();
            }

            return;
        }
        $keys = $name === 'ticket_links' ? ['ticket_id', 'linked_ticket_id'] : ['ticket_id'];
        if ($name === 'attachments' || $name === 'audit_logs') {
            $type_key = $name === 'attachments' ? 'attachable_type' : 'auditable_type';
            $id_key = $name === 'attachments' ? 'attachable_id' : 'auditable_id';
            if (! $insert && ! array_key_exists($type_key, $row) && ! array_key_exists($id_key, $row)) {
                return;
            }
            if ($name === 'audit_logs' && ($row[$type_key] ?? null) !== 'Ticket' && ($insert || array_key_exists($type_key, $row))) {
                return;
            }
            $kind = $row[$type_key] ?? '';
            if ($kind === 'ticket' || $kind === 'Ticket') {
                TicketAccess::require_ticket((int) ($row[$id_key] ?? 0));

                return;
            }
            if ($kind === 'reply') {
                $parent = \Escalated\Models\Reply::find((int) ($row[$id_key] ?? 0));
                if ($parent) {
                    return;
                }
            }
            self::deny();
        }
        if ($name === 'side_conversation_replies') {
            if ($insert || array_key_exists('side_conversation_id', $row)) {
                $db = Escalated::db();
                $table = Escalated::table('side_conversations');
                if (! $db->get_var($db->prepare("SELECT id FROM `{$table}` WHERE id = %d", $row['side_conversation_id'] ?? 0))) {
                    self::deny();
                }
            }

            return;
        }
        foreach ($keys as $key) {
            if ($insert || array_key_exists($key, $row)) {
                TicketAccess::require_ticket((int) ($row[$key] ?? 0));
            }
        }
        if ($name === 'inbound_emails' && isset($row['reply_id']) && ! \Escalated\Models\Reply::find((int) $row['reply_id'])) {
            self::deny();
        }
    }

    private static function literal(array $token)
    {
        if (($token['kind'] ?? '') === 'string') {
            return $token['value'];
        }
        if (strtoupper($token['value'] ?? '') === 'NULL') {
            return null;
        }
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $token['value'] ?? '')) {
            return $token['value'];
        }
        self::deny();
    }

    private static function deny(): void
    {
        throw new \InvalidArgumentException('Ticket access denied or unsupported SQL under ticket restrictions.');
    }

    private static function tokens(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $start = $i;
            $char = $sql[$i];
            if (ctype_space($char)) {
                $i++;

                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }
            if ($char === '#' || (substr($sql, $i, 2) === '--' && ctype_space($sql[$i + 2] ?? ' '))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;

                continue;
            }
            $value = '';
            $kind = 'symbol';
            if (in_array($char, ["'", '"', '`'], true)) {
                // Double quotes may be identifiers under MySQL ANSI_QUOTES.
                $kind = $char === "'" ? 'string' : 'identifier';
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === $char) {
                        $i++;
                        if (($sql[$i] ?? '') !== $char) {
                            break;
                        }
                    } elseif ($sql[$i] === '\\' && $char !== '`' && $i + 1 < $length) {
                        $i++;
                        $value .= match ($sql[$i]) {
                            '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => chr(26), default => $sql[$i]
                        };
                        $i++;

                        continue;
                    }
                    $value .= $sql[$i++];
                }
            } elseif (ctype_alnum($char) || $char === '_' || $char === '$') {
                $kind = 'identifier';
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$')) {
                    $value .= $sql[$i++];
                }
            } else {
                $value = $sql[$i++];
            }
            $tokens[] = ['kind' => $kind, 'value' => $value, 'text' => substr($sql, $start, $i - $start), 'offset' => $start];
        }

        return $tokens;
    }
}
