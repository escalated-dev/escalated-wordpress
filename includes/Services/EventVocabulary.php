<?php

namespace Escalated\Services;

/**
 * The event names from escalated-developer-context ADR
 * decisions/2026-10-21-event-vocabulary.md.
 *
 * The plugin emits and offers only canonical names. Legacy names are still
 * accepted wherever stored data is read (a workflow's trigger_event, a
 * webhook's subscribed events) and are treated as their canonical name.
 */
final class EventVocabulary
{
    /**
     * Legacy name => canonical name.
     */
    public const LEGACY = [
        'ticket.replied' => 'reply.created',
        'internal_note.added' => 'note.created',
        'internal_note.created' => 'note.created',
        'tag.added' => 'ticket.tag_added',
        'ticket.tagged' => 'ticket.tag_added',
        'tag.removed' => 'ticket.tag_removed',
        'ticket.untagged' => 'ticket.tag_removed',
        'chat.session_started' => 'chat.started',
        'chat.session_ended' => 'chat.ended',
    ];

    /**
     * The events NotificationService sends to webhooks, the same list as
     * escalated-laravel's DispatchWebhook.
     */
    public const WEBHOOK_EVENTS = [
        'ticket.created',
        'ticket.updated',
        'ticket.status_changed',
        'ticket.resolved',
        'ticket.closed',
        'ticket.reopened',
        'ticket.assigned',
        'ticket.unassigned',
        'ticket.escalated',
        'ticket.priority_changed',
        'ticket.department_changed',
        'reply.created',
        'note.created',
        'sla.breached',
        'sla.warning',
        'ticket.tag_added',
        'ticket.tag_removed',
    ];

    /**
     * The events WorkflowListener runs workflows on. Live chat has no
     * transfer, so chat.transferred is not fired. reply.agent_reply is a
     * WordPress extension: reply.created limited to staff replies.
     */
    public const WORKFLOW_TRIGGERS = [
        'ticket.created',
        'ticket.updated',
        'ticket.status_changed',
        'ticket.resolved',
        'ticket.closed',
        'ticket.reopened',
        'ticket.assigned',
        'ticket.unassigned',
        'ticket.escalated',
        'ticket.priority_changed',
        'ticket.department_changed',
        'ticket.tag_added',
        'ticket.tag_removed',
        'reply.created',
        'reply.agent_reply',
        'note.created',
        'sla.warning',
        'sla.breached',
        'chat.started',
        'chat.assigned',
        'chat.message',
        'chat.ended',
    ];

    public static function canonical(string $name): string
    {
        return self::LEGACY[$name] ?? $name;
    }

    /**
     * The canonical name plus every legacy name that means it.
     *
     * @return list<string>
     */
    public static function names_for(string $canonical): array
    {
        return array_values(array_unique([$canonical, ...array_keys(self::LEGACY, $canonical, true)]));
    }

    /**
     * Canonicalise a list of stored names, dropping duplicates.
     *
     * @param  array<int, mixed>  $names
     * @return list<string>
     */
    public static function canonical_list(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = self::canonical($name);
            }
        }

        return array_values(array_unique($out));
    }
}
