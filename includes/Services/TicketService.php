<?php

namespace Escalated\Services;

use Escalated\Helpers\Enums;
use Escalated\Models\Reply;
use Escalated\Models\Tag;
use Escalated\Models\Ticket;
use Escalated\Models\TicketActivity;

class TicketService
{
    /**
     * Create a new ticket for an authenticated user.
     *
     * @param  int  $requester_id  WordPress user ID of the requester.
     * @param  array  $data  Ticket data including subject, description, priority, channel, department_id, metadata, tags.
     * @return object The created ticket.
     */
    public function create(int $requester_id, array $data): object
    {
        $reference = Ticket::generate_reference();
        $now = current_time('mysql');
        $priority = $data['priority'] ?? \Escalated\Models\Setting::get('default_priority', 'medium');
        $valid_priorities = array_keys(\Escalated\Helpers\Enums::ticket_priorities());

        $ticket_data = [
            'reference' => $reference,
            'requester_id' => $requester_id,
            'subject' => sanitize_text_field($data['subject']),
            'description' => wp_kses_post($data['description']),
            'status' => 'open',
            'priority' => in_array($priority, $valid_priorities, true) ? $priority : 'medium',
            'ticket_type' => in_array($data['ticket_type'] ?? '', ['question', 'problem', 'incident', 'task'], true) ? $data['ticket_type'] : 'question',
            'channel' => sanitize_text_field($data['channel'] ?? 'web'),
            'department_id' => ! empty($data['department_id']) ? absint($data['department_id']) : null,
            'metadata' => ! empty($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ticket_id = Ticket::create($ticket_data);
        $ticket = Ticket::find($ticket_id);

        if (! empty($data['tags'])) {
            foreach ((array) $data['tags'] as $tag_id) {
                Tag::attach($ticket_id, absint($tag_id));
            }
        }

        $this->log_activity($ticket_id, $requester_id, 'status_changed', [
            'new_status' => 'open',
        ]);

        do_action('escalated_ticket_created', $ticket);

        return $ticket;
    }

    /**
     * Create a new ticket for a guest (unauthenticated) user.
     *
     * @param  array  $data  Ticket data including subject, description, guest_name, guest_email, etc.
     * @return object The created ticket.
     */
    public function create_guest(array $data): object
    {
        $reference = Ticket::generate_reference();
        $now = current_time('mysql');
        $priority = $data['priority'] ?? \Escalated\Models\Setting::get('default_priority', 'medium');
        $valid_priorities = array_keys(\Escalated\Helpers\Enums::ticket_priorities());

        $guest_email = sanitize_email($data['guest_email'] ?? '');
        $guest_name = sanitize_text_field($data['guest_name'] ?? '');

        // Dedupe repeat guests by email (Pattern B). Inline guest_* fields
        // remain populated for the backwards-compat dual-read period.
        $contact_id = null;
        if (! empty($guest_email)) {
            $contact = \Escalated\Models\Contact::find_or_create_by_email(
                $guest_email,
                $guest_name ?: null
            );
            if ($contact && isset($contact->id)) {
                $contact_id = (int) $contact->id;
            }
        }

        // Apply the admin-configured guest policy from the
        // public-tickets settings page. Persisted under three keys:
        //   - guest_policy_mode: unassigned | guest_user | prompt_signup
        //   - guest_policy_user_id: WordPress user id for guest_user mode
        //   - guest_policy_signup_url_template: for prompt_signup mode
        //
        // Modes:
        //   - unassigned (default): requester_id = null
        //   - guest_user: requester_id = configured shared WordPress user
        //   - prompt_signup: same as unassigned for ticket creation;
        //     signup-invite emission is a listener-level follow-up
        //
        // Misconfigured guest_user (zero/missing id) falls through to
        // unassigned so bad admin input can't 500 public submissions.
        $requester_id = null;
        $policy_mode = \Escalated\Models\Setting::get('guest_policy_mode', 'unassigned');
        if ($policy_mode === 'guest_user') {
            $guest_user_id = (int) \Escalated\Models\Setting::get('guest_policy_user_id', 0);
            if ($guest_user_id > 0) {
                $requester_id = $guest_user_id;
            }
        }

        $ticket_data = [
            'reference' => $reference,
            'requester_id' => $requester_id,
            'subject' => sanitize_text_field($data['subject']),
            'description' => wp_kses_post($data['description']),
            'status' => 'open',
            'priority' => in_array($priority, $valid_priorities, true) ? $priority : 'medium',
            'ticket_type' => in_array($data['ticket_type'] ?? '', ['question', 'problem', 'incident', 'task'], true) ? $data['ticket_type'] : 'question',
            'channel' => sanitize_text_field($data['channel'] ?? 'web'),
            'department_id' => ! empty($data['department_id']) ? absint($data['department_id']) : null,
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_token' => wp_generate_password(64, false),
            'contact_id' => $contact_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ticket_id = Ticket::create($ticket_data);
        $ticket = Ticket::find($ticket_id);

        $this->log_activity($ticket_id, null, 'status_changed', [
            'new_status' => 'open',
        ]);

        do_action('escalated_ticket_created', $ticket);

        return $ticket;
    }

    /**
     * Update a ticket's editable fields (subject, description, metadata).
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  array  $data  Fields to update.
     * @return object The updated ticket.
     */
    public function update_ticket(int $ticket_id, array $data): object
    {
        $allowed = ['subject', 'description', 'metadata', 'ticket_type'];
        $update = [];
        foreach ($allowed as $key) {
            if (isset($data[$key])) {
                if ($key === 'metadata') {
                    $update[$key] = wp_json_encode($data[$key]);
                } elseif ($key === 'description') {
                    $update[$key] = wp_kses_post($data[$key]);
                } elseif ($key === 'ticket_type') {
                    if (in_array($data[$key], ['question', 'problem', 'incident', 'task'], true)) {
                        $update[$key] = $data[$key];
                    }
                } else {
                    $update[$key] = sanitize_text_field($data[$key]);
                }
            }
        }
        if (! empty($update)) {
            Ticket::update($ticket_id, $update);
        }
        $ticket = Ticket::find($ticket_id);
        do_action('escalated_ticket_updated', $ticket);

        return $ticket;
    }

    /**
     * Change a ticket's status with transition validation.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  string  $new_status  The target status.
     * @param  int|null  $causer_id  User who triggered the change.
     * @return object The updated ticket.
     *
     * @throws \InvalidArgumentException If the ticket is not found or the transition is invalid.
     */
    public function change_status(int $ticket_id, string $new_status, ?int $causer_id = null): object
    {
        $ticket = Ticket::find($ticket_id);
        if (! $ticket) {
            throw new \InvalidArgumentException('Ticket not found.');
        }

        $old_status = $ticket->status;

        if (! Enums::can_transition($old_status, $new_status)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot transition from %s to %s', $old_status, $new_status)
            );
        }

        $update = ['status' => $new_status];
        $now = current_time('mysql');

        if ($new_status === 'resolved') {
            $update['resolved_at'] = $now;
        } elseif ($new_status === 'closed') {
            $update['closed_at'] = $now;
        } elseif ($new_status === 'reopened') {
            $update['resolved_at'] = null;
            $update['closed_at'] = null;
        }

        Ticket::update($ticket_id, $update);
        $ticket = Ticket::find($ticket_id);

        $this->log_activity($ticket_id, $causer_id, 'status_changed', [
            'old_status' => $old_status,
            'new_status' => $new_status,
        ]);

        do_action('escalated_ticket_status_changed', $ticket, $old_status, $new_status, $causer_id);

        if ($new_status === 'resolved') {
            do_action('escalated_ticket_resolved', $ticket, $causer_id);
        } elseif ($new_status === 'closed') {
            do_action('escalated_ticket_closed', $ticket, $causer_id);
        } elseif ($new_status === 'reopened') {
            do_action('escalated_ticket_reopened', $ticket, $causer_id);
        } elseif ($new_status === 'escalated') {
            do_action('escalated_ticket_escalated', $ticket);
        }

        return $ticket;
    }

    /**
     * Add a reply to a ticket.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int  $author_id  User ID of the reply author.
     * @param  string  $body  Reply body content.
     * @param  array  $attachments  Array of file uploads ($_FILES style).
     * @return object The created reply.
     */
    public function reply(int $ticket_id, int $author_id, string $body, array $attachments = []): object
    {
        $now = current_time('mysql');
        $reply_id = Reply::create([
            'ticket_id' => $ticket_id,
            'author_id' => $author_id,
            'body' => wp_kses_post($body),
            'is_internal_note' => 0,
            'type' => 'reply',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! empty($attachments)) {
            $attachment_service = new AttachmentService;
            foreach ($attachments as $file) {
                $attachment_service->store('reply', $reply_id, $file);
            }
        }

        // Record first response time if this is an agent responding.
        $ticket = Ticket::find($ticket_id);
        if ($ticket && empty($ticket->first_response_at) && $author_id !== (int) $ticket->requester_id) {
            Ticket::update($ticket_id, ['first_response_at' => $now]);
        }

        $this->log_activity($ticket_id, $author_id, 'replied');

        $reply = Reply::find($reply_id);
        do_action('escalated_reply_created', $reply, $ticket);

        return $reply;
    }

    /**
     * Add an internal note to a ticket.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int  $author_id  User ID of the note author.
     * @param  string  $body  Note body content.
     * @param  array  $attachments  Array of file uploads.
     * @return object The created note (reply with is_internal_note = 1).
     */
    public function add_note(int $ticket_id, int $author_id, string $body, array $attachments = []): object
    {
        $now = current_time('mysql');
        $reply_id = Reply::create([
            'ticket_id' => $ticket_id,
            'author_id' => $author_id,
            'body' => wp_kses_post($body),
            'is_internal_note' => 1,
            'type' => 'note',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! empty($attachments)) {
            $attachment_service = new AttachmentService;
            foreach ($attachments as $file) {
                $attachment_service->store('reply', $reply_id, $file);
            }
        }

        $this->log_activity($ticket_id, $author_id, 'note_added');

        $reply = Reply::find($reply_id);
        do_action('escalated_internal_note_added', $reply, Ticket::find($ticket_id));

        return $reply;
    }

    /**
     * Change a ticket's priority.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  string  $new_priority  The new priority value.
     * @param  int|null  $causer_id  User who triggered the change.
     * @return object The updated ticket.
     */
    public function change_priority(int $ticket_id, string $new_priority, ?int $causer_id = null): object
    {
        $ticket = Ticket::find($ticket_id);
        $old_priority = $ticket->priority;

        Ticket::update($ticket_id, ['priority' => $new_priority]);

        $this->log_activity($ticket_id, $causer_id, 'priority_changed', [
            'old_priority' => $old_priority,
            'new_priority' => $new_priority,
        ]);

        $ticket = Ticket::find($ticket_id);
        do_action('escalated_ticket_updated', $ticket);

        return $ticket;
    }

    /**
     * Add tags to a ticket.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  array  $tag_ids  Array of tag IDs to attach.
     * @param  int|null  $causer_id  User who triggered the change.
     */
    public function add_tags(int $ticket_id, array $tag_ids, ?int $causer_id = null): void
    {
        foreach ($tag_ids as $tag_id) {
            Tag::attach($ticket_id, absint($tag_id));
            $this->log_activity($ticket_id, $causer_id, 'tag_added', ['tag_id' => $tag_id]);
            do_action('escalated_tag_added', $ticket_id, $tag_id);
        }
    }

    /**
     * Remove tags from a ticket.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  array  $tag_ids  Array of tag IDs to detach.
     * @param  int|null  $causer_id  User who triggered the change.
     */
    public function remove_tags(int $ticket_id, array $tag_ids, ?int $causer_id = null): void
    {
        foreach ($tag_ids as $tag_id) {
            Tag::detach($ticket_id, absint($tag_id));
            $this->log_activity($ticket_id, $causer_id, 'tag_removed', ['tag_id' => $tag_id]);
            do_action('escalated_tag_removed', $ticket_id, $tag_id);
        }
    }

    /**
     * Change a ticket's department.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int  $department_id  New department ID.
     * @param  int|null  $causer_id  User who triggered the change.
     * @return object The updated ticket.
     */
    public function change_department(int $ticket_id, int $department_id, ?int $causer_id = null): object
    {
        $ticket = Ticket::find($ticket_id);
        $old_department_id = $ticket->department_id;

        Ticket::update($ticket_id, ['department_id' => $department_id]);

        $this->log_activity($ticket_id, $causer_id, 'department_changed', [
            'old_department_id' => $old_department_id,
            'new_department_id' => $department_id,
        ]);

        $ticket = Ticket::find($ticket_id);
        do_action('escalated_department_changed', $ticket, $old_department_id, $department_id, $causer_id);

        return $ticket;
    }

    /**
     * Close a ticket (shorthand for change_status to 'closed').
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int|null  $causer_id  User who triggered the close.
     * @return object The updated ticket.
     */
    public function close(int $ticket_id, ?int $causer_id = null): object
    {
        return $this->change_status($ticket_id, 'closed', $causer_id);
    }

    /**
     * Resolve a ticket (shorthand for change_status to 'resolved').
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int|null  $causer_id  User who triggered the resolve.
     * @return object The updated ticket.
     */
    public function resolve(int $ticket_id, ?int $causer_id = null): object
    {
        return $this->change_status($ticket_id, 'resolved', $causer_id);
    }

    /**
     * Reopen a ticket (shorthand for change_status to 'reopened').
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int|null  $causer_id  User who triggered the reopen.
     * @return object The updated ticket.
     */
    public function reopen(int $ticket_id, ?int $causer_id = null): object
    {
        return $this->change_status($ticket_id, 'reopened', $causer_id);
    }

    /**
     * Log a ticket activity entry.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @param  int|null  $causer_id  User who caused the activity.
     * @param  string  $type  Activity type (see Enums::activity_types).
     * @param  array  $properties  Additional properties to store as JSON.
     */
    protected function log_activity(int $ticket_id, ?int $causer_id, string $type, array $properties = []): void
    {
        TicketActivity::create([
            'ticket_id' => $ticket_id,
            'causer_id' => $causer_id,
            'type' => $type,
            'properties' => ! empty($properties) ? wp_json_encode($properties) : null,
            'created_at' => current_time('mysql'),
        ]);
    }
}
