<?php

namespace Escalated\Services;

use Escalated\Models\Setting;
use Escalated\Models\Ticket;

class NotificationService
{
    /**
     * Hook ticket and reply emails and the webhooks into the ticket
     * lifecycle. Called once from Escalated::boot(). Webhooks carry the
     * canonical names in EventVocabulary::WEBHOOK_EVENTS.
     *
     * Priority 100 runs after the product-side handlers (10-20) and workflows
     * (50), and after Email_Threading (5) has set the threading context for
     * the emails sent here.
     */
    public function register(): void
    {
        add_action('escalated_ticket_created', [$this, 'on_ticket_created'], 100, 1);
        add_action('escalated_ticket_updated', [$this, 'on_ticket_updated'], 100, 1);
        add_action('escalated_ticket_status_changed', [$this, 'on_ticket_status_changed'], 100, 4);
        add_action('escalated_ticket_assigned', [$this, 'on_ticket_assigned'], 100, 4);
        add_action('escalated_ticket_unassigned', [$this, 'on_ticket_unassigned'], 100, 3);
        add_action('escalated_department_changed', [$this, 'on_department_changed'], 100, 4);
        add_action('escalated_reply_created', [$this, 'on_reply_created'], 100, 2);
        add_action('escalated_sla_breached', [$this, 'on_sla_breached'], 100, 2);
        add_action('escalated_sla_warning', [$this, 'on_sla_warning'], 100, 3);
        add_action('escalated_ticket_resolved', [$this, 'on_ticket_resolved'], 100, 2);
        add_action('escalated_ticket_closed', [$this, 'on_ticket_closed'], 100, 2);
        add_action('escalated_ticket_reopened', [$this, 'on_ticket_reopened'], 100, 2);
        add_action('escalated_ticket_escalated', [$this, 'on_ticket_escalated'], 100, 1);
        add_action('escalated_ticket_priority_changed', [$this, 'on_ticket_priority_changed'], 100, 4);
        add_action('escalated_internal_note_added', [$this, 'on_note_created'], 100, 2);
        add_action('escalated_tag_added', [$this, 'on_tag_added'], 100, 2);
        add_action('escalated_tag_removed', [$this, 'on_tag_removed'], 100, 2);
        add_action('escalated_chat_started', [$this, 'on_chat_started'], 100, 1);
        add_action('escalated_chat_accepted', [$this, 'on_chat_assigned'], 100, 2);
        add_action('escalated_chat_transferred', [$this, 'on_chat_transferred'], 100, 4);
        add_action('escalated_chat_message', [$this, 'on_chat_message'], 100, 3);
        add_action('escalated_chat_ended', [$this, 'on_chat_ended'], 100, 3);
    }

    public function on_ticket_resolved($ticket, $causer_id = null): void
    {
        $this->dispatch_ticket_event('ticket.resolved', $ticket, ['causer_id' => self::nullable_int($causer_id)]);
    }

    public function on_ticket_closed($ticket, $causer_id = null): void
    {
        $this->dispatch_ticket_event('ticket.closed', $ticket, ['causer_id' => self::nullable_int($causer_id)]);
    }

    public function on_ticket_reopened($ticket, $causer_id = null): void
    {
        $this->dispatch_ticket_event('ticket.reopened', $ticket, ['causer_id' => self::nullable_int($causer_id)]);
    }

    public function on_ticket_escalated($ticket): void
    {
        $this->dispatch_ticket_event('ticket.escalated', $ticket);
    }

    public function on_ticket_priority_changed($ticket, $old_priority = null, $new_priority = null, $causer_id = null): void
    {
        $this->dispatch_ticket_event('ticket.priority_changed', $ticket, [
            'old_priority' => $old_priority,
            'new_priority' => $new_priority,
            'causer_id' => self::nullable_int($causer_id),
        ]);
    }

    /**
     * Internal notes are sent as note.created with the note's id and author,
     * never its body, as escalated-laravel does.
     */
    public function on_note_created($note, $ticket = null): void
    {
        if (! is_object($note) || empty($note->id)) {
            return;
        }
        if (! $this->is_ticket($ticket) && ! empty($note->ticket_id)) {
            $ticket = Ticket::find((int) $note->ticket_id);
        }
        $this->dispatch_ticket_event('note.created', $ticket, [
            'note' => [
                'id' => (int) $note->id,
                'author_id' => self::nullable_int($note->author_id ?? null),
                'created_at' => $note->created_at ?? null,
            ],
        ]);
    }

    public function on_chat_started($session): void
    {
        $this->dispatch_chat_event('chat.started', $session);
    }

    public function on_chat_assigned($session, $agent_id = null): void
    {
        $this->dispatch_chat_event('chat.assigned', $session);
    }

    public function on_chat_transferred($session, $from_agent_id = null, $to_agent_id = null, $to_department_id = null): void
    {
        $this->dispatch_chat_event('chat.transferred', $session, [
            'from_agent_id' => self::nullable_int($from_agent_id),
            'to_agent_id' => self::nullable_int($to_agent_id),
            'to_department_id' => self::nullable_int($to_department_id),
        ]);
    }

    /**
     * chat.message carries the reply's id and who wrote it, never the body:
     * a subscriber reads that through the API.
     */
    public function on_chat_message($session, $reply = null, $author_type = 'visitor'): void
    {
        $this->dispatch_chat_event('chat.message', $session, [
            'reply_id' => is_object($reply) && ! empty($reply->id) ? (int) $reply->id : null,
            'author_type' => $author_type === 'agent' ? 'agent' : 'visitor',
        ]);
    }

    public function on_chat_ended($session, $causer_id = null, $ended_by = 'system'): void
    {
        $this->dispatch_chat_event('chat.ended', $session, [
            'ended_by' => in_array($ended_by, ['agent', 'customer', 'system'], true) ? $ended_by : 'system',
        ]);
    }

    /**
     * A chat event with escalated-laravel's DispatchWebhook chat fields, plus
     * the ticket. The visitor's email, the chat token and message bodies
     * stay out.
     */
    protected function dispatch_chat_event(string $event, $session, array $extra = []): void
    {
        if (! is_object($session) || empty($session->ticket_id)) {
            return;
        }
        $this->dispatch_ticket_event($event, Ticket::find((int) $session->ticket_id), [
            'chat' => [
                'session_id' => (int) $session->id,
                'ticket_id' => (int) $session->ticket_id,
                'status' => $session->status ?? null,
                'agent_id' => self::nullable_int($session->agent_id ?? null),
                'department_id' => self::nullable_int($session->department_id ?? null),
            ] + $extra,
        ]);
    }

    public function on_tag_added($ticket_id, $tag_id = null): void
    {
        $this->dispatch_tag_event('ticket.tag_added', $ticket_id, $tag_id);
    }

    public function on_tag_removed($ticket_id, $tag_id = null): void
    {
        $this->dispatch_tag_event('ticket.tag_removed', $ticket_id, $tag_id);
    }

    /**
     * SlaService::check_warnings() runs every minute and fires the warning
     * again while the ticket is inside the window. Send it once per ticket,
     * SLA target and due time.
     */
    public function on_sla_warning($ticket, $sla_type = null, $due_at = null): void
    {
        if (! $this->is_ticket($ticket)) {
            return;
        }
        $key = 'escalated_wh_sla_warning_'.md5($ticket->id.'|'.$sla_type.'|'.$due_at);
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, WEEK_IN_SECONDS);

        $this->dispatch_ticket_event('sla.warning', $ticket, [
            'sla_type' => $sla_type,
            'due_at' => $due_at,
        ]);
    }

    protected function dispatch_tag_event(string $event, $ticket_id, $tag_id): void
    {
        if (! is_numeric($ticket_id)) {
            return;
        }
        $ticket = Ticket::find((int) $ticket_id);
        $tag = $tag_id ? \Escalated\Models\Tag::find((int) $tag_id) : null;
        $this->dispatch_ticket_event($event, $ticket, [
            'tag' => [
                'id' => self::nullable_int($tag_id),
                'name' => $tag->name ?? null,
            ],
        ]);
    }

    protected function dispatch_ticket_event(string $event, $ticket, array $extra = []): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook($event, ['ticket' => $this->ticket_payload($ticket)] + $extra);
        }
    }

    public function on_ticket_created($ticket): void
    {
        if (! $this->is_ticket($ticket)) {
            return;
        }

        if (Setting::get_bool('notification_new_ticket', true)) {
            $this->safely('ticket.created email', fn () => $this->notify_ticket_created($ticket));
        }

        $this->dispatch_webhook('ticket.created', ['ticket' => $this->ticket_payload($ticket)]);
    }

    public function on_ticket_updated($ticket): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook('ticket.updated', ['ticket' => $this->ticket_payload($ticket)]);
        }
    }

    public function on_ticket_status_changed($ticket, $old_status = null, $new_status = null, $causer_id = null): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook('ticket.status_changed', [
                'ticket' => $this->ticket_payload($ticket),
                'old_status' => $old_status,
                'new_status' => $new_status,
                'causer_id' => self::nullable_int($causer_id),
            ]);
        }
    }

    public function on_ticket_assigned($ticket, $new_agent_id = null, $old_agent_id = null, $causer_id = null): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook('ticket.assigned', [
                'ticket' => $this->ticket_payload($ticket),
                'new_agent_id' => self::nullable_int($new_agent_id),
                'old_agent_id' => self::nullable_int($old_agent_id),
                'causer_id' => self::nullable_int($causer_id),
            ]);
        }
    }

    public function on_ticket_unassigned($ticket, $old_agent_id = null, $causer_id = null): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook('ticket.unassigned', [
                'ticket' => $this->ticket_payload($ticket),
                'old_agent_id' => self::nullable_int($old_agent_id),
                'causer_id' => self::nullable_int($causer_id),
            ]);
        }
    }

    public function on_department_changed($ticket, $old_department_id = null, $new_department_id = null, $causer_id = null): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook('ticket.department_changed', [
                'ticket' => $this->ticket_payload($ticket),
                'old_department_id' => self::nullable_int($old_department_id),
                'new_department_id' => self::nullable_int($new_department_id),
                'causer_id' => self::nullable_int($causer_id),
            ]);
        }
    }

    public function on_reply_created($reply, $ticket = null): void
    {
        if (! is_object($reply) || empty($reply->id)) {
            return;
        }
        if (! $this->is_ticket($ticket) && ! empty($reply->ticket_id)) {
            $ticket = Ticket::find((int) $reply->ticket_id);
        }
        if (! $this->is_ticket($ticket)) {
            return;
        }

        if (Setting::get_bool('notification_ticket_reply', true)) {
            $this->safely('reply.created email', fn () => $this->notify_reply_created($reply, $ticket));
        }

        // Internal notes never leave the site.
        if (! empty($reply->is_internal_note)) {
            return;
        }

        $this->dispatch_webhook('reply.created', [
            'ticket' => $this->ticket_payload($ticket),
            'reply' => [
                'id' => (int) $reply->id,
                'author_id' => self::nullable_int($reply->author_id ?? null),
                'body' => $reply->body ?? '',
                'created_at' => $reply->created_at ?? null,
            ],
        ]);
    }

    public function on_sla_breached($ticket, $breach_type = null): void
    {
        if ($this->is_ticket($ticket)) {
            $this->dispatch_webhook('sla.breached', [
                'ticket' => $this->ticket_payload($ticket),
                'breach_type' => $breach_type,
            ]);
        }
    }

    /**
     * Queue the event for every webhook subscribed to it. Delivery happens
     * later, from WP-Cron (see WebhookDispatcher), so a slow receiver never
     * holds up the request. A failure never escapes into the operation that
     * fired the hook.
     */
    protected function dispatch_webhook(string $event, array $payload): void
    {
        $this->safely($event.' webhook', fn () => (new WebhookDispatcher)->dispatch($event, $payload));
    }

    protected function safely(string $what, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[Escalated\\NotificationService] %s failed: %s', $what, $e->getMessage()));
            }
        }
    }

    protected function is_ticket($ticket): bool
    {
        return is_object($ticket) && ! empty($ticket->id);
    }

    /**
     * The ticket fields a webhook receiver gets. Guest tokens and contact
     * details are left out.
     */
    protected function ticket_payload(object $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'reference' => $ticket->reference ?? null,
            'subject' => $ticket->subject ?? null,
            'status' => $ticket->status ?? null,
            'priority' => $ticket->priority ?? null,
            'ticket_type' => $ticket->ticket_type ?? null,
            'channel' => $ticket->channel ?? null,
            'department_id' => self::nullable_int($ticket->department_id ?? null),
            'assigned_to' => self::nullable_int($ticket->assigned_to ?? null),
            'requester_id' => self::nullable_int($ticket->requester_id ?? null),
            'created_at' => $ticket->created_at ?? null,
            'updated_at' => $ticket->updated_at ?? null,
        ];
    }

    private static function nullable_int($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * The From header for notification emails: the notification sender
     * settings, falling back to the site name and admin email.
     */
    protected function from_header(): string
    {
        $name = trim((string) Setting::get('notification_from_name', ''));
        $email = (string) Setting::get('notification_from_email', '');

        return sprintf(
            'From: %s <%s>',
            $name !== '' ? $name : get_bloginfo('name'),
            is_email($email) ? $email : get_option('admin_email')
        );
    }

    /**
     * Queue a webhook event for every subscribed webhook.
     *
     * Before subscriptions this POSTed to the single webhook_url setting
     * straight away; that setting is now folded into a subscription.
     *
     * @param  string  $event  The event name (e.g., 'ticket.created', 'reply.created').
     * @param  array  $payload  The event data to send.
     * @return bool True when at least one delivery was queued.
     */
    public function send_webhook(string $event, array $payload): bool
    {
        return (new WebhookDispatcher)->dispatch($event, $payload) > 0;
    }

    /**
     * Send notification emails when a ticket is created.
     *
     * Sends notifications to:
     * - Site admin (or configured admin email).
     * - Assigned agent (if the ticket is already assigned).
     * - Department agents (if the ticket belongs to a department).
     *
     * @param  object  $ticket  The newly created ticket object.
     */
    public function notify_ticket_created(object $ticket): void
    {
        if (TicketAccess::enabled()) {
            $ticket = TicketAccess::without_restriction(static fn () => \Escalated\Models\Ticket::find((int) $ticket->id));
            if (! $ticket) {
                return;
            }
        }
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');

        $subject = sprintf(
            /* translators: 1: ticket reference, 2: ticket subject */
            __('[%1$s] New Ticket: %2$s', 'escalated'),
            esc_html($ticket->reference),
            esc_html($ticket->subject)
        );

        // Build the email body.
        $requester_name = $this->get_requester_name($ticket);

        $body = sprintf(
            /* translators: 1: site name */
            __('A new support ticket has been submitted on %s.', 'escalated'),
            esc_html($site_name)
        )."\n\n";

        $body .= sprintf(
            __('Reference: %s', 'escalated'),
            esc_html($ticket->reference)
        )."\n";

        $body .= sprintf(
            __('Subject: %s', 'escalated'),
            esc_html($ticket->subject)
        )."\n";

        $body .= sprintf(
            __('Priority: %s', 'escalated'),
            esc_html(ucfirst($ticket->priority))
        )."\n";

        $body .= sprintf(
            __('From: %s', 'escalated'),
            esc_html($requester_name)
        )."\n\n";

        $body .= __('Description:', 'escalated')."\n";
        $body .= wp_strip_all_tags($ticket->description)."\n\n";

        // Admin ticket URL.
        $admin_url = admin_url('admin.php?page=escalated-tickets&action=view&id='.$ticket->id);
        $body .= sprintf(
            __('View ticket: %s', 'escalated'),
            $admin_url
        )."\n";

        /**
         * Filter the ticket created notification email body.
         *
         * @param  string  $body  The email body.
         * @param  object  $ticket  The ticket object.
         */
        $body = apply_filters('escalated_notify_ticket_created_body', $body, $ticket);

        /**
         * Filter the ticket created notification email subject.
         *
         * @param  string  $subject  The email subject.
         * @param  object  $ticket  The ticket object.
         */
        $subject = apply_filters('escalated_notify_ticket_created_subject', $subject, $ticket);

        // Collect recipient emails.
        $recipients = [$admin_email];

        // Add assigned agent email.
        if (! empty($ticket->assigned_to)) {
            $agent = get_userdata((int) $ticket->assigned_to);
            if ($agent && ! empty($agent->user_email)) {
                $recipients[] = $agent->user_email;
            }
        }

        // Add department agents if applicable.
        if (! empty($ticket->department_id)) {
            $agent_ids = \Escalated\Models\Department::agents((int) $ticket->department_id);
            foreach ($agent_ids as $agent_id) {
                $agent = get_userdata((int) $agent_id);
                if ($agent && ! empty($agent->user_email)) {
                    $recipients[] = $agent->user_email;
                }
            }
        }

        // Remove duplicates.
        $recipients = array_unique($recipients);

        /**
         * Filter the ticket created notification recipients.
         *
         * @param  array  $recipients  Array of email addresses.
         * @param  object  $ticket  The ticket object.
         */
        $recipients = apply_filters('escalated_notify_ticket_created_recipients', $recipients, $ticket);
        $recipients = $this->authorized_recipients($recipients, $ticket);

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            $this->from_header(),
        ];

        foreach ($recipients as $recipient_email) {
            if (is_email($recipient_email)) {
                wp_mail($recipient_email, $subject, $body, $headers);
            }
        }

        do_action('escalated_notification_ticket_created_sent', $ticket, $recipients);
    }

    /**
     * Send notification emails when a reply is created on a ticket.
     *
     * Sends notifications to relevant users: the requester, the assigned agent,
     * and any ticket followers. Internal notes do not trigger notifications
     * to the requester.
     *
     * @param  object  $reply  The newly created reply object.
     * @param  object  $ticket  The ticket the reply belongs to.
     */
    public function notify_reply_created(object $reply, object $ticket): void
    {
        if (TicketAccess::enabled()) {
            $ticket = TicketAccess::without_restriction(static fn () => \Escalated\Models\Ticket::find((int) $ticket->id));
            if (! $ticket) {
                return;
            }
        }
        // Do not send external notifications for internal notes.
        $is_internal = ! empty($reply->is_internal_note);

        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');

        $subject = sprintf(
            /* translators: 1: ticket reference, 2: ticket subject */
            __('Re: [%1$s] %2$s', 'escalated'),
            esc_html($ticket->reference),
            esc_html($ticket->subject)
        );

        // Get the author name.
        $author_name = __('System', 'escalated');
        if (! empty($reply->author_id)) {
            $author = get_userdata((int) $reply->author_id);
            if ($author) {
                $author_name = $author->display_name;
            }
        }

        $body = sprintf(
            /* translators: 1: author name, 2: ticket reference */
            __('%1$s replied to ticket %2$s:', 'escalated'),
            esc_html($author_name),
            esc_html($ticket->reference)
        )."\n\n";

        if ($is_internal) {
            $body .= __('[Internal Note]', 'escalated')."\n\n";
        }

        $body .= wp_strip_all_tags($reply->body)."\n\n";

        // Admin ticket URL.
        $admin_url = admin_url('admin.php?page=escalated-tickets&action=view&id='.$ticket->id);
        $body .= sprintf(
            __('View ticket: %s', 'escalated'),
            $admin_url
        )."\n";

        /**
         * Filter the reply created notification email body.
         *
         * @param  string  $body  The email body.
         * @param  object  $reply  The reply object.
         * @param  object  $ticket  The ticket object.
         */
        $body = apply_filters('escalated_notify_reply_created_body', $body, $reply, $ticket);

        /**
         * Filter the reply created notification email subject.
         *
         * @param  string  $subject  The email subject.
         * @param  object  $reply  The reply object.
         * @param  object  $ticket  The ticket object.
         */
        $subject = apply_filters('escalated_notify_reply_created_subject', $subject, $reply, $ticket);

        $recipients = [];

        if ($is_internal) {
            // Internal notes: notify only agents and admin, not the requester.
            if (! empty($ticket->assigned_to) && (int) $ticket->assigned_to !== (int) $reply->author_id) {
                $agent = get_userdata((int) $ticket->assigned_to);
                if ($agent && ! empty($agent->user_email)) {
                    $recipients[] = $agent->user_email;
                }
            }

            // Notify admin for internal notes.
            $recipients[] = $admin_email;
        } else {
            // Public replies: notify the requester (if not the author).
            if (! empty($ticket->requester_id) && (int) $ticket->requester_id !== (int) $reply->author_id) {
                $requester = get_userdata((int) $ticket->requester_id);
                if ($requester && ! empty($requester->user_email)) {
                    $recipients[] = $requester->user_email;
                }
            } elseif (empty($ticket->requester_id) && ! empty($ticket->guest_email)) {
                // Guest ticket: notify the guest email.
                $recipients[] = $ticket->guest_email;
            }

            // Notify the assigned agent (if they are not the author).
            if (! empty($ticket->assigned_to) && (int) $ticket->assigned_to !== (int) $reply->author_id) {
                $agent = get_userdata((int) $ticket->assigned_to);
                if ($agent && ! empty($agent->user_email)) {
                    $recipients[] = $agent->user_email;
                }
            }
        }

        // Add ticket followers.
        $followers = $this->get_ticket_followers((int) $ticket->id);
        foreach ($followers as $follower_id) {
            // Skip the reply author.
            if ((int) $follower_id === (int) $reply->author_id) {
                continue;
            }
            // For internal notes, skip non-agents (the requester).
            if ($is_internal && (int) $follower_id === (int) $ticket->requester_id) {
                continue;
            }
            $follower = get_userdata((int) $follower_id);
            if ($follower && ! empty($follower->user_email)) {
                $recipients[] = $follower->user_email;
            }
        }

        // Remove duplicates.
        $recipients = array_unique($recipients);

        /**
         * Filter the reply created notification recipients.
         *
         * @param  array  $recipients  Array of email addresses.
         * @param  object  $reply  The reply object.
         * @param  object  $ticket  The ticket object.
         */
        $recipients = apply_filters('escalated_notify_reply_created_recipients', $recipients, $reply, $ticket);
        $recipients = $this->authorized_recipients($recipients, $ticket, ! $is_internal);

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            $this->from_header(),
            sprintf('References: <%s@%s>', $ticket->reference, wp_parse_url(home_url(), PHP_URL_HOST)),
        ];

        foreach ($recipients as $recipient_email) {
            if (is_email($recipient_email)) {
                wp_mail($recipient_email, $subject, $body, $headers);
            }
        }

        do_action('escalated_notification_reply_created_sent', $reply, $ticket, $recipients);
    }

    /**
     * Get the display name of a ticket's requester.
     *
     * @param  object  $ticket  The ticket object.
     * @return string The requester's display name, or guest name/email.
     */
    private function authorized_recipients(array $recipients, object $ticket, bool $public_reply = false): array
    {
        if (! TicketAccess::enabled()) {
            return $recipients;
        }
        $requester = ! empty($ticket->requester_id) ? get_userdata((int) $ticket->requester_id) : null;
        $requester_email = $requester ? $requester->user_email : ($ticket->guest_email ?? null);

        return array_values(array_filter($recipients, static function ($email) use ($ticket, $public_reply, $requester_email) {
            $user = get_user_by('email', $email);
            if ($user && TicketAccess::is_staff((int) $user->ID)) {
                return TicketAccess::can_receive((int) $ticket->id, (int) $user->ID);
            }

            return $public_reply && $requester_email && strcasecmp($email, $requester_email) === 0;
        }));
    }

    protected function get_requester_name(object $ticket): string
    {
        if (! empty($ticket->requester_id)) {
            $user = get_userdata((int) $ticket->requester_id);
            if ($user) {
                return $user->display_name;
            }
        }

        if (! empty($ticket->guest_name)) {
            return $ticket->guest_name;
        }

        if (! empty($ticket->guest_email)) {
            return $ticket->guest_email;
        }

        return __('Unknown', 'escalated');
    }

    /**
     * Get all follower user IDs for a ticket.
     *
     * @param  int  $ticket_id  Ticket ID.
     * @return array Array of WordPress user IDs.
     */
    protected function get_ticket_followers(int $ticket_id): array
    {
        $wpdb = \Escalated\Escalated::db();

        $table = \Escalated\Escalated::table('ticket_followers');

        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$table} WHERE ticket_id = %d",
                $ticket_id
            )
        );

        return $results ?: [];
    }
}
