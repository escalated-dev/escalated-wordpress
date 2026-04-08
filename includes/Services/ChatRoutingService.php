<?php

namespace Escalated\Services;

use Escalated\Models\ChatRoutingRule;

class ChatRoutingService
{
    /**
     * Resolve the best routing target for a new chat session.
     *
     * @param  int|null  $requested_department_id  The department the visitor selected (if any).
     * @return array{department_id: int|null, agent_id: int|null}
     */
    public function resolve(?int $requested_department_id = null): array
    {
        $rules = ChatRoutingRule::get_active();

        foreach ($rules as $rule) {
            if ($rule->department_id && (int) $rule->department_id === $requested_department_id) {
                return [
                    'department_id' => (int) $rule->department_id,
                    'agent_id' => $rule->agent_id ? (int) $rule->agent_id : null,
                ];
            }

            if ($requested_department_id === null) {
                return [
                    'department_id' => $rule->department_id ? (int) $rule->department_id : null,
                    'agent_id' => $rule->agent_id ? (int) $rule->agent_id : null,
                ];
            }
        }

        return [
            'department_id' => $requested_department_id,
            'agent_id' => null,
        ];
    }

    /**
     * Create a new routing rule.
     */
    public function create_rule(array $data): int
    {
        $rule_data = [
            'name' => sanitize_text_field($data['name']),
            'department_id' => ! empty($data['department_id']) ? absint($data['department_id']) : null,
            'agent_id' => ! empty($data['agent_id']) ? absint($data['agent_id']) : null,
            'conditions' => ! empty($data['conditions']) ? wp_json_encode($data['conditions']) : null,
            'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => 1,
        ];

        return ChatRoutingRule::create($rule_data);
    }

    /**
     * Get all active routing rules.
     */
    public function get_rules(): array
    {
        return ChatRoutingRule::get_active();
    }

    /**
     * Delete a routing rule.
     */
    public function delete_rule(int $rule_id): bool
    {
        return ChatRoutingRule::delete($rule_id);
    }
}
