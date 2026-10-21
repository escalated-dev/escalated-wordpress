<?php

namespace Escalated\Services;

use Escalated\Models\Workflow;

/**
 * Validation and form options for the workflow admin surfaces (REST and the
 * wp-admin screen), following domain-model/workflow-admin-contract.md.
 */
class WorkflowAdmin
{
    /**
     * Fields the builder offers. The contract's list plus `account`, which
     * WorkflowEngine evaluates as an account identity.
     */
    public const CONDITION_FIELDS = ['status', 'priority', 'ticket_type', 'channel', 'subject', 'description', 'tags', 'department_id', 'assigned_to', 'hours_since_created', 'hours_since_updated'];

    /**
     * The actions offered in a new workflow: the contract's core catalog,
     * the optional ones the executor handles, and set_type. Legacy aliases
     * the executor still runs (change_department) are not offered.
     */
    public const OFFERED_ACTIONS = ['change_status', 'change_priority', 'add_tag', 'remove_tag', 'set_department', 'assign_agent', 'add_note', 'insert_canned_reply', 'add_follower', 'delay', 'send_webhook', 'set_type'];

    /**
     * Validate a contract request body.
     *
     * @param  array<string, mixed>  $input
     * @param  bool  $partial  For an update: only the keys present are checked.
     * @return array{0: array<string, mixed>, 1: array<string, string>} [data to store, errors]
     */
    public static function validate(array $input, bool $partial = false): array
    {
        $data = [];
        $errors = [];

        if (! $partial || array_key_exists('name', $input)) {
            $name = is_string($input['name'] ?? null) ? sanitize_text_field($input['name']) : '';
            if ($name === '') {
                $errors['name'] = __('The name is required.', 'escalated');
            } elseif (mb_strlen($name) > 255) {
                $errors['name'] = __('The name may not be longer than 255 characters.', 'escalated');
            } else {
                $data['name'] = $name;
            }
        }

        if (! $partial || array_key_exists('trigger_event', $input)) {
            $trigger = is_string($input['trigger_event'] ?? null) ? EventVocabulary::canonical(trim($input['trigger_event'])) : '';
            if ($trigger === '') {
                $errors['trigger_event'] = __('The trigger event is required.', 'escalated');
            } elseif (! in_array($trigger, WorkflowEngine::TRIGGER_EVENTS, true)) {
                $errors['trigger_event'] = __('The trigger event is not one this site fires.', 'escalated');
            } else {
                $data['trigger_event'] = $trigger;
            }
        }

        if (! $partial || array_key_exists('conditions', $input)) {
            [$conditions, $error] = self::validate_conditions($input['conditions'] ?? null);
            if ($error !== null) {
                $errors['conditions'] = $error;
            } else {
                $data['conditions'] = $conditions;
            }
        }

        if (! $partial || array_key_exists('actions', $input)) {
            [$actions, $error] = self::validate_actions($input['actions'] ?? null);
            if ($error !== null) {
                $errors['actions'] = $error;
            } else {
                $data['actions'] = $actions;
            }
        }

        if (array_key_exists('is_active', $input)) {
            $data['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        } elseif (! $partial) {
            $data['is_active'] = true;
        }

        if (array_key_exists('stop_on_match', $input)) {
            $data['stop_on_match'] = filter_var($input['stop_on_match'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('position', $input) && $input['position'] !== null && $input['position'] !== '') {
            if (! is_numeric($input['position']) || (int) $input['position'] < 0) {
                $errors['position'] = __('The position must be a whole number.', 'escalated');
            } else {
                $data['position'] = (int) $input['position'];
            }
        }

        return [$data, $errors];
    }

    /**
     * @return array{0: ?array, 1: ?string}
     */
    private static function validate_conditions($conditions): array
    {
        if ($conditions === null || $conditions === '' || $conditions === []) {
            return [['all' => []], null];
        }
        if (! is_array($conditions)) {
            return [null, __('Conditions must be an object with "all" or "any".', 'escalated')];
        }

        $keys = array_keys($conditions);
        if (array_is_list($conditions) || count($keys) !== 1 || ! in_array($keys[0], ['all', 'any'], true) || ! is_array($conditions[$keys[0]])) {
            return [null, __('Conditions must have exactly one of "all" or "any", holding a list.', 'escalated')];
        }

        $match = $keys[0];
        $out = [];
        foreach (array_values($conditions[$match]) as $i => $condition) {
            if (! is_array($condition)) {
                return [null, sprintf(__('Condition %d must be an object.', 'escalated'), $i + 1)];
            }
            $field = is_string($condition['field'] ?? null) ? trim($condition['field']) : '';
            $operator = is_string($condition['operator'] ?? null) ? trim($condition['operator']) : 'equals';
            $value = $condition['value'] ?? '';
            if ($field === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
                return [null, sprintf(__('Condition %d needs a field.', 'escalated'), $i + 1)];
            }
            if (! in_array($operator, WorkflowEngine::OPERATORS, true)) {
                return [null, sprintf(__('Condition %1$d uses an unknown operator: %2$s.', 'escalated'), $i + 1, $operator)];
            }
            if (! is_scalar($value) && $value !== null) {
                return [null, sprintf(__('Condition %d needs a text value.', 'escalated'), $i + 1)];
            }
            $out[] = ['field' => $field, 'operator' => $operator, 'value' => (string) $value];
        }

        return [[$match => $out], null];
    }

    /**
     * @return array{0: ?array, 1: ?string}
     */
    private static function validate_actions($actions): array
    {
        if (! is_array($actions) || $actions === [] || ! array_is_list($actions)) {
            return [null, __('Add at least one action.', 'escalated')];
        }

        $out = [];
        foreach ($actions as $i => $action) {
            if (! is_array($action)) {
                return [null, sprintf(__('Action %d must be an object.', 'escalated'), $i + 1)];
            }
            $type = is_string($action['type'] ?? null) ? trim($action['type']) : '';
            if (! in_array($type, WorkflowEngine::ACTION_TYPES, true)) {
                return [null, sprintf(__('Action %1$d has an unknown type: %2$s.', 'escalated'), $i + 1, $type)];
            }
            $value = $action['value'] ?? '';
            if (! is_scalar($value) && $value !== null) {
                return [null, sprintf(__('Action %d needs a text value.', 'escalated'), $i + 1)];
            }
            $out[] = ['type' => $type, 'value' => (string) $value];
        }

        return [$out, null];
    }

    /**
     * The Form page props: the triggers WorkflowListener fires, the actions
     * the executor handles, and the operators the engine evaluates.
     *
     * @return array<string, mixed>
     */
    public static function form_options(): array
    {
        return [
            'trigger_events' => array_map(
                static fn ($event) => ['value' => $event, 'label' => self::trigger_label($event)],
                WorkflowEngine::TRIGGER_EVENTS
            ),
            'action_types' => array_map(
                static fn ($type) => ['value' => $type, 'label' => self::action_label($type)],
                self::OFFERED_ACTIONS
            ),
            'operators' => array_values(WorkflowEngine::OPERATORS),
            'condition_fields' => self::CONDITION_FIELDS,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all_formatted(): array
    {
        return array_map([Workflow::class, 'format'], Workflow::all());
    }

    public static function trigger_label(string $event): string
    {
        $labels = [
            'ticket.created' => __('Ticket created', 'escalated'),
            'ticket.updated' => __('Ticket updated', 'escalated'),
            'ticket.status_changed' => __('Ticket status changed', 'escalated'),
            'ticket.resolved' => __('Ticket resolved', 'escalated'),
            'ticket.closed' => __('Ticket closed', 'escalated'),
            'ticket.reopened' => __('Ticket reopened', 'escalated'),
            'ticket.assigned' => __('Ticket assigned', 'escalated'),
            'ticket.unassigned' => __('Ticket unassigned', 'escalated'),
            'ticket.escalated' => __('Ticket escalated', 'escalated'),
            'ticket.priority_changed' => __('Ticket priority changed', 'escalated'),
            'ticket.department_changed' => __('Ticket department changed', 'escalated'),
            'ticket.tag_added' => __('Tag added', 'escalated'),
            'ticket.tag_removed' => __('Tag removed', 'escalated'),
            'reply.created' => __('Reply created', 'escalated'),
            'reply.agent_reply' => __('Agent replied', 'escalated'),
            'note.created' => __('Internal note added', 'escalated'),
            'sla.warning' => __('SLA warning', 'escalated'),
            'sla.breached' => __('SLA breached', 'escalated'),
            'chat.started' => __('Chat started', 'escalated'),
            'chat.assigned' => __('Chat assigned', 'escalated'),
            'chat.message' => __('Chat message', 'escalated'),
            'chat.ended' => __('Chat ended', 'escalated'),
        ];

        return $labels[$event] ?? $event;
    }

    public static function action_label(string $type): string
    {
        $labels = [
            'change_status' => __('Change status', 'escalated'),
            'change_priority' => __('Change priority', 'escalated'),
            'add_tag' => __('Add tag', 'escalated'),
            'remove_tag' => __('Remove tag', 'escalated'),
            'set_department' => __('Set department', 'escalated'),
            'assign_agent' => __('Assign agent', 'escalated'),
            'add_note' => __('Add internal note', 'escalated'),
            'insert_canned_reply' => __('Insert reply', 'escalated'),
            'add_follower' => __('Add follower', 'escalated'),
            'delay' => __('Delay (minutes)', 'escalated'),
            'send_webhook' => __('Send webhook', 'escalated'),
            'set_type' => __('Set ticket type', 'escalated'),
        ];

        return $labels[$type] ?? $type;
    }
}
