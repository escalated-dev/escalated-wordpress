<?php

namespace Escalated\Contracts;

/**
 * A host-app entity that can be attached to a ticket as its *subject* — the
 * thing the ticket is about (Project, Customer, asset, …), distinct from the
 * requester and the subject *line* (free text).
 *
 * WordPress does not own host models; integrators return objects implementing
 * this contract from the {@see TicketSubjectService::resolve()} filter.
 */
interface TicketSubject
{
    /** Primary label (e.g. "Acme Website Redesign"). */
    public function ticketSubjectTitle(): string;

    /** Secondary line (e.g. "Project · Acme Corp"). Null to omit. */
    public function ticketSubjectSubtitle(): ?string;

    /** Deep link into the host app. Null for non-clickable. */
    public function ticketSubjectUrl(): ?string;

    /** Accent color hex or design token. Null for default. */
    public function ticketSubjectColor(): ?string;

    /** Icon slug for the frontend icon map. Null for default. */
    public function ticketSubjectIcon(): ?string;
}
