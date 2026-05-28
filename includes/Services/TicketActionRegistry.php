<?php

namespace Escalated\Services;

/**
 * Resolves host-defined custom ticket actions.
 *
 * Host plugins register actions via the `escalated_ticket_actions` filter,
 * returning an array of action configs. Each visible action renders as a button
 * on the agent ticket screen; triggering it fires the
 * `escalated_ticket_action_triggered` action so the host can handle the work.
 *
 * Action config shape (all but key/label optional):
 *   [
 *     'key'          => 'sync-crm',          // required, stable id
 *     'label'        => 'Sync CRM',          // required
 *     'variant'      => 'primary',           // primary|secondary|danger
 *     'visible'      => true,                // bool or callable($ticket, $user_id)
 *     'enabled'      => true,                // bool or callable($ticket, $user_id)
 *     'confirmation' => 'Are you sure?',     // ?string or callable
 *     'metadata'     => ['icon' => '...'],   // array or callable
 *   ]
 *
 * Mirrors the Laravel TicketActionRegistry / NestJS reference.
 */
class TicketActionRegistry
{
    /**
     * All registered actions, keyed by their `key`, with defaults applied.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $actions = apply_filters('escalated_ticket_actions', []);

        if (! is_array($actions)) {
            return [];
        }

        $normalized = [];
        foreach ($actions as $action) {
            if (empty($action['key']) || empty($action['label'])) {
                continue;
            }
            $normalized[(string) $action['key']] = $action;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public static function is_visible(array $action, object $ticket, int $user_id): bool
    {
        return (bool) self::resolve($action['visible'] ?? true, $ticket, $user_id);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public static function is_enabled(array $action, object $ticket, int $user_id): bool
    {
        return (bool) self::resolve($action['enabled'] ?? true, $ticket, $user_id);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public static function metadata(array $action, object $ticket, int $user_id): array
    {
        $metadata = self::resolve($action['metadata'] ?? [], $ticket, $user_id);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * The visible actions for a ticket/user, serialized for the UI. The
     * controller adds the `url` and `method` before sending to the client.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visible_for(object $ticket, int $user_id): array
    {
        $result = [];

        foreach (self::all() as $action) {
            if (! self::is_visible($action, $ticket, $user_id)) {
                continue;
            }

            $confirmation = self::resolve($action['confirmation'] ?? null, $ticket, $user_id);

            $result[] = [
                'key' => (string) $action['key'],
                'label' => (string) self::resolve($action['label'], $ticket, $user_id),
                'variant' => (string) ($action['variant'] ?? 'secondary'),
                'confirmation' => $confirmation === null ? null : (string) $confirmation,
                'disabled' => ! self::is_enabled($action, $ticket, $user_id),
                'metadata' => self::metadata($action, $ticket, $user_id),
            ];
        }

        return $result;
    }

    /**
     * Resolve a value that may be a static value or a callable($ticket, $user_id).
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected static function resolve($value, object $ticket, int $user_id)
    {
        return is_callable($value) ? $value($ticket, $user_id) : $value;
    }
}
