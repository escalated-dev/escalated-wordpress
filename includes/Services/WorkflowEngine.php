<?php

namespace Jeremykenedy\Escalated\Services;

class WorkflowEngine
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal', 'is_empty', 'is_not_empty'];

    public const ACTION_TYPES = ['change_status', 'assign_agent', 'change_priority', 'add_tag', 'remove_tag', 'set_department', 'add_note', 'send_webhook', 'set_type', 'delay', 'add_follower', 'send_notification'];

    public const TRIGGER_EVENTS = ['ticket.created', 'ticket.updated', 'ticket.status_changed', 'ticket.assigned', 'ticket.priority_changed', 'ticket.tagged', 'ticket.department_changed', 'reply.created', 'reply.agent_reply', 'sla.warning', 'sla.breached', 'ticket.reopened'];

    public function evaluateConditions(array $conditions, array $ticket): bool
    {
        if (isset($conditions['all'])) {
            foreach ($conditions['all'] as $c) {
                if (! $this->evaluateSingle($c, $ticket)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($conditions['any'])) {
            foreach ($conditions['any'] as $c) {
                if ($this->evaluateSingle($c, $ticket)) {
                    return true;
                }
            }

            return false;
        }
        if (array_is_list($conditions)) {
            foreach ($conditions as $c) {
                if (! $this->evaluateSingle($c, $ticket)) {
                    return false;
                }
            }

            return true;
        }

        return isset($conditions['field']) && $this->evaluateSingle($conditions, $ticket);
    }

    public function evaluateSingle(array $condition, array $ticket): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $expected = $condition['value'] ?? '';
        $actual = $ticket[$field] ?? '';

        return self::applyOperator($operator, (string) $actual, (string) $expected);
    }

    public static function applyOperator(string $op, string $actual, string $expected): bool
    {
        return match ($op) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'contains' => str_contains($actual, $expected),
            'not_contains' => ! str_contains($actual, $expected),
            'starts_with' => str_starts_with($actual, $expected),
            'ends_with' => str_ends_with($actual, $expected),
            'greater_than' => (float) $actual > (float) $expected,
            'less_than' => (float) $actual < (float) $expected,
            'greater_or_equal' => (float) $actual >= (float) $expected,
            'less_or_equal' => (float) $actual <= (float) $expected,
            'is_empty' => trim($actual) === '',
            'is_not_empty' => trim($actual) !== '',
            default => false,
        };
    }

    public static function interpolateVariables(string $text, array $ticket): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($ticket) {
            return $ticket[$m[1]] ?? $m[0];
        }, $text);
    }

    public function dryRun(array $conditions, array $actions, array $ticket): array
    {
        $matched = $this->evaluateConditions($conditions, $ticket);

        return [
            'matched' => $matched,
            'actions' => array_map(fn ($a) => [
                'type' => $a['type'],
                'value' => self::interpolateVariables($a['value'] ?? '', $ticket),
                'would_execute' => $matched,
            ], $actions),
        ];
    }
}
