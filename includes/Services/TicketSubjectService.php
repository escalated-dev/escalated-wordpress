<?php

namespace Escalated\Services;

use Escalated\Contracts\TicketSubject;
use Escalated\Models\TicketSubjectLink;

/**
 * Attach/detach/sync host entities a ticket is *about*, and serialize them
 * for the ticket API payload.
 */
class TicketSubjectService
{
    public const OPTION_ALLOWLIST = 'escalated_ticket_subject_types';

    public const FILTER_RESOLVE = 'escalated_resolve_ticket_subject';

    /**
     * Allowed morph types from the WP option (flat list of type strings).
     *
     * @return string[]
     */
    public static function allowlist(): array
    {
        $types = get_option(self::OPTION_ALLOWLIST, []);

        if (! is_array($types)) {
            return [];
        }

        $flat = [];
        foreach ($types as $key => $value) {
            if (is_string($key) && $key !== '') {
                $flat[] = $key;
            }
            if (is_string($value) && $value !== '') {
                $flat[] = $value;
            }
        }

        return array_values(array_unique($flat));
    }

    /**
     * @param  bool  $for_api  When true, empty allowlist means no types are permitted.
     */
    public static function is_type_allowed(string $type, bool $for_api = false): bool
    {
        $allowed = self::allowlist();

        if ($allowed === []) {
            return ! $for_api;
        }

        return in_array($type, $allowed, true);
    }

    /**
     * Resolve a host subject via filter. Returns null when not found.
     *
     * @return object|null Should implement {@see TicketSubject} for full presentation.
     */
    public static function resolve(string $type, string $id): ?object
    {
        $subject = apply_filters(self::FILTER_RESOLVE, null, $type, $id);

        return is_object($subject) ? $subject : null;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function attach(
        int $ticket_id,
        string $type,
        string $id,
        ?string $role = null,
        ?int $position = null,
        bool $enforce_allowlist = true
    ): object {
        $type = sanitize_text_field($type);
        $id = (string) $id;

        if ($enforce_allowlist && ! self::is_type_allowed($type, false) && self::allowlist() !== []) {
            throw new \InvalidArgumentException(
                sprintf('Subject type [%s] is not an allowed ticket subject.', $type)
            );
        }

        $link = TicketSubjectLink::upsert($ticket_id, $type, $id, $role, $position);

        if ($link === null) {
            throw new \RuntimeException('Failed to attach ticket subject.');
        }

        return $link;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function attach_for_api(int $ticket_id, string $type, string $id, ?string $role = null): object
    {
        if (! self::is_type_allowed($type, true)) {
            throw new \InvalidArgumentException(
                sprintf('Subject type [%s] is not an allowed ticket subject.', $type)
            );
        }

        if (self::resolve($type, $id) === null) {
            throw new \InvalidArgumentException('No matching subject was found.');
        }

        return self::attach($ticket_id, $type, $id, $role, null, true);
    }

    public static function detach(int $ticket_id, string $type, string $id): int
    {
        return TicketSubjectLink::detach(
            $ticket_id,
            sanitize_text_field($type),
            (string) $id
        );
    }

    public static function detach_by_link_id(int $ticket_id, int $link_id): int
    {
        return TicketSubjectLink::delete_by_id($link_id, $ticket_id);
    }

    /**
     * Replace all subjects. Each entry is ['type' => ..., 'id' => ..., 'role' => ?]
     * or a numeric array [type, id] or [type, id, role].
     *
     * @param  array<int, array<string, mixed>|array<int, string>>  $subjects
     * @throws \InvalidArgumentException
     */
    public static function sync(int $ticket_id, array $subjects, bool $enforce_allowlist = true): void
    {
        TicketSubjectLink::delete_for_ticket($ticket_id);

        $position = 0;
        foreach ($subjects as $entry) {
            if (isset($entry['type'], $entry['id'])) {
                $type = (string) $entry['type'];
                $id = (string) $entry['id'];
                $role = isset($entry['role']) ? (string) $entry['role'] : null;
            } elseif (is_array($entry) && isset($entry[0], $entry[1])) {
                $type = (string) $entry[0];
                $id = (string) $entry[1];
                $role = isset($entry[2]) ? (string) $entry[2] : null;
            } else {
                continue;
            }

            self::attach($ticket_id, $type, $id, $role, $position++, $enforce_allowlist);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function serialize_for_ticket(int $ticket_id): array
    {
        $links = TicketSubjectLink::for_ticket($ticket_id);
        $out = [];

        foreach ($links as $link) {
            $out[] = self::serialize_link($link);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function serialize_link(object $link): array
    {
        $type = (string) $link->subject_type;
        $id = (string) $link->subject_id;
        $resolved = self::resolve($type, $id);
        $presents = $resolved instanceof TicketSubject;

        $fallback_title = $type.'#'.$id;

        return [
            'type' => esc_html($type),
            'id' => esc_html($id),
            'role' => $link->role !== null ? esc_html((string) $link->role) : null,
            'title' => esc_html(
                $presents
                    ? $resolved->ticketSubjectTitle()
                    : $fallback_title
            ),
            'subtitle' => $presents && $resolved->ticketSubjectSubtitle() !== null
                ? esc_html($resolved->ticketSubjectSubtitle())
                : null,
            'url' => $presents && $resolved->ticketSubjectUrl() !== null
                ? esc_url($resolved->ticketSubjectUrl())
                : null,
            'color' => $presents && $resolved->ticketSubjectColor() !== null
                ? esc_html($resolved->ticketSubjectColor())
                : null,
            'icon' => $presents && $resolved->ticketSubjectIcon() !== null
                ? esc_html($resolved->ticketSubjectIcon())
                : null,
            'missing' => $resolved === null,
        ];
    }
}
