<?php

namespace Escalated\Services;

use Escalated\Escalated;
use Escalated\Helpers\Enums;
use Escalated\Models\Ticket;
use Escalated\Models\Setting;

class InboundEmailService {

    /**
     * Process an inbound email message.
     *
     * Logs the raw inbound email, checks for duplicates, attempts to find an existing
     * ticket (by reference or email headers), finds or identifies the sender, and either
     * adds a reply to an existing ticket or creates a new one.
     *
     * @param array  $message Inbound email data with keys: fromEmail, fromName, toEmail,
     *                        subject, bodyText, bodyHtml, messageId, inReplyTo, references,
     *                        headers, attachments.
     * @param string $adapter The inbound email adapter name (e.g., 'sendgrid', 'mailgun', 'postmark').
     * @return object|null The ticket that was created or replied to, or null on failure.
     */
    public function process( array $message, string $adapter ): ?object {
        global $wpdb;

        $inbound_table = Escalated::table( 'inbound_emails' );
        $now = current_time( 'mysql' );

        // Log the inbound email.
        $inbound_data = [
            'message_id'  => sanitize_text_field( $message['messageId'] ?? '' ),
            'from_email'  => sanitize_email( $message['fromEmail'] ?? '' ),
            'from_name'   => sanitize_text_field( $message['fromName'] ?? '' ),
            'to_email'    => sanitize_email( $message['toEmail'] ?? '' ),
            'subject'     => sanitize_text_field( $message['subject'] ?? '' ),
            'body_text'   => wp_kses_post( $message['bodyText'] ?? '' ),
            'body_html'   => wp_kses_post( $message['bodyHtml'] ?? '' ),
            'raw_headers' => wp_kses_post( wp_json_encode( $message['headers'] ?? [] ) ),
            'status'      => 'pending',
            'adapter'     => sanitize_text_field( $adapter ),
            'created_at'  => $now,
        ];

        $wpdb->insert( $inbound_table, $inbound_data );
        $inbound_id = $wpdb->insert_id;

        // Check for duplicate message ID.
        if ( ! empty( $message['messageId'] ) ) {
            $duplicate_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$inbound_table} WHERE message_id = %s AND id != %d",
                    $message['messageId'],
                    $inbound_id
                )
            );

            if ( $duplicate_count > 0 ) {
                $wpdb->update(
                    $inbound_table,
                    [
                        'status'        => 'duplicate',
                        'error_message' => 'Duplicate message ID detected.',
                        'processed_at'  => $now,
                    ],
                    [ 'id' => $inbound_id ]
                );
                return null;
            }
        }

        try {
            // Try to find an existing ticket this email belongs to.
            $ticket = $this->find_ticket_by_email( $message );

            // Find the WordPress user by email.
            $from_email = sanitize_email( $message['fromEmail'] ?? '' );
            $user = $this->find_user_by_email( $from_email );

            if ( $ticket ) {
                // Add reply to existing ticket.
                $reply = $this->add_reply_to_ticket( $ticket, $message, $user );

                $wpdb->update(
                    $inbound_table,
                    [
                        'ticket_id'    => $ticket->id,
                        'reply_id'     => $reply ? $reply->id : null,
                        'status'       => 'processed',
                        'processed_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $inbound_id ]
                );
            } else {
                // Create a new ticket.
                $ticket = $this->create_new_ticket( $message, $user );

                $wpdb->update(
                    $inbound_table,
                    [
                        'ticket_id'    => $ticket ? $ticket->id : null,
                        'status'       => 'processed',
                        'processed_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $inbound_id ]
                );
            }

            // Process attachments if present.
            if ( ! empty( $message['attachments'] ) && $ticket ) {
                $attachable_type = isset( $reply ) ? 'reply' : 'ticket';
                $attachable_id   = isset( $reply ) ? (int) $reply->id : (int) $ticket->id;

                $this->store_inbound_attachments( $attachable_type, $attachable_id, $message['attachments'] );
            }

            do_action( 'escalated_inbound_email_processed', $ticket, $message, $adapter );

            return $ticket;

        } catch ( \Exception $e ) {
            $wpdb->update(
                $inbound_table,
                [
                    'status'        => 'failed',
                    'error_message' => sanitize_text_field( $e->getMessage() ),
                    'processed_at'  => current_time( 'mysql' ),
                ],
                [ 'id' => $inbound_id ]
            );

            do_action( 'escalated_inbound_email_failed', $inbound_id, $e->getMessage(), $message );

            return null;
        }
    }

    /**
     * Find an existing ticket by parsing email subject and headers.
     *
     * Checks the subject line for a ticket reference pattern (e.g., [ESC-00001]),
     * and checks the In-Reply-To and References headers for matching message IDs
     * stored in previous inbound email records.
     *
     * @param array $message Inbound email data.
     * @return object|null The matching ticket, or null if not found.
     */
    public function find_ticket_by_email( array $message ): ?object {
        global $wpdb;

        $subject = $message['subject'] ?? '';

        // Check subject for ticket reference pattern like [ESC-00001].
        if ( preg_match( '/\[([A-Z]+-\d{5,})\]/', $subject, $matches ) ) {
            $reference = $matches[1];
            $ticket = Ticket::find_by_reference( $reference );
            if ( $ticket ) {
                return $ticket;
            }
        }

        // Check In-Reply-To header.
        $inbound_table = Escalated::table( 'inbound_emails' );

        if ( ! empty( $message['inReplyTo'] ) ) {
            $related = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ticket_id FROM {$inbound_table} WHERE message_id = %s AND ticket_id IS NOT NULL LIMIT 1",
                    $message['inReplyTo']
                )
            );

            if ( $related && ! empty( $related->ticket_id ) ) {
                $ticket = Ticket::find( (int) $related->ticket_id );
                if ( $ticket ) {
                    return $ticket;
                }
            }
        }

        // Check References header (may contain multiple message IDs).
        if ( ! empty( $message['references'] ) ) {
            $references = is_array( $message['references'] )
                ? $message['references']
                : preg_split( '/\s+/', $message['references'] );

            foreach ( $references as $ref_message_id ) {
                $ref_message_id = trim( $ref_message_id );
                if ( empty( $ref_message_id ) ) {
                    continue;
                }

                $related = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT ticket_id FROM {$inbound_table} WHERE message_id = %s AND ticket_id IS NOT NULL LIMIT 1",
                        $ref_message_id
                    )
                );

                if ( $related && ! empty( $related->ticket_id ) ) {
                    $ticket = Ticket::find( (int) $related->ticket_id );
                    if ( $ticket ) {
                        return $ticket;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find a WordPress user by email address.
     *
     * @param string $email Email address to look up.
     * @return \WP_User|null The WordPress user, or null if not found.
     */
    public function find_user_by_email( string $email ): ?\WP_User {
        if ( empty( $email ) ) {
            return null;
        }

        $user = get_user_by( 'email', $email );

        return $user instanceof \WP_User ? $user : null;
    }

    /**
     * Add a reply to an existing ticket from an inbound email.
     *
     * If the ticket is resolved or closed, it will be reopened before adding the reply.
     *
     * @param object        $ticket  The existing ticket object.
     * @param array         $message Inbound email data.
     * @param \WP_User|null $user    The WordPress user who sent the email, or null for guest.
     * @return object|null The created reply, or null on failure.
     */
    public function add_reply_to_ticket( object $ticket, array $message, ?\WP_User $user ): ?object {
        $ticket_service = new TicketService();
        $body = $this->get_sanitized_body( $message );

        // Reopen ticket if it is resolved or closed.
        if ( in_array( $ticket->status, [ 'resolved', 'closed' ], true ) ) {
            try {
                $ticket_service->reopen( (int) $ticket->id, $user ? $user->ID : null );
            } catch ( \InvalidArgumentException $e ) {
                // If we cannot reopen, continue with the reply anyway.
            }
        }

        if ( $user ) {
            return $ticket_service->reply( (int) $ticket->id, $user->ID, $body );
        }

        // Guest reply: create reply directly without an author_id.
        $now = current_time( 'mysql' );
        $reply_id = \Escalated\Models\Reply::create( [
            'ticket_id'        => (int) $ticket->id,
            'author_id'        => null,
            'body'             => wp_kses_post( $body ),
            'is_internal_note' => 0,
            'type'             => 'reply',
            'metadata'         => wp_json_encode( [
                'guest_email' => sanitize_email( $message['fromEmail'] ?? '' ),
                'guest_name'  => sanitize_text_field( $message['fromName'] ?? '' ),
                'channel'     => 'email',
            ] ),
            'created_at'       => $now,
            'updated_at'       => $now,
        ] );

        if ( ! $reply_id ) {
            return null;
        }

        $reply = \Escalated\Models\Reply::find( $reply_id );
        do_action( 'escalated_reply_created', $reply, $ticket );

        return $reply;
    }

    /**
     * Create a new ticket from an inbound email.
     *
     * If a WordPress user is found for the sender, creates an authenticated ticket.
     * Otherwise, creates a guest ticket with the sender's email and name.
     *
     * @param array         $message Inbound email data.
     * @param \WP_User|null $user    The WordPress user who sent the email, or null for guest.
     * @return object|null The created ticket, or null on failure.
     */
    public function create_new_ticket( array $message, ?\WP_User $user ): ?object {
        $ticket_service = new TicketService();
        $body    = $this->get_sanitized_body( $message );
        $subject = $this->sanitize_subject( $message['subject'] ?? '' );

        if ( empty( $subject ) ) {
            $subject = __( '(No Subject)', 'escalated' );
        }

        $ticket_data = [
            'subject'     => $subject,
            'description' => $body,
            'channel'     => 'email',
            'metadata'    => [
                'from_email'  => sanitize_email( $message['fromEmail'] ?? '' ),
                'message_id'  => sanitize_text_field( $message['messageId'] ?? '' ),
                'source'      => 'inbound_email',
            ],
        ];

        if ( $user ) {
            return $ticket_service->create( $user->ID, $ticket_data );
        }

        // Guest ticket.
        $ticket_data['guest_name']  = sanitize_text_field( $message['fromName'] ?? '' );
        $ticket_data['guest_email'] = sanitize_email( $message['fromEmail'] ?? '' );

        return $ticket_service->create_guest( $ticket_data );
    }

    /**
     * Sanitize an email subject line.
     *
     * Removes common prefixes (RE:, FW:, Fwd:) and ticket reference brackets.
     *
     * @param string $subject The raw email subject.
     * @return string The cleaned subject line.
     */
    public function sanitize_subject( string $subject ): string {
        // Remove RE:/FW:/Fwd: prefixes (case-insensitive, repeated).
        $subject = preg_replace( '/^(\s*(RE|FW|FWD)\s*:\s*)+/i', '', $subject );

        // Remove ticket reference brackets like [ESC-00001].
        $subject = preg_replace( '/\s*\[[A-Z]+-\d{5,}\]\s*/', ' ', $subject );

        return sanitize_text_field( trim( $subject ) );
    }

    /**
     * Get a sanitized email body, preferring plain text over HTML.
     *
     * Falls back to HTML body with tags stripped if plain text is not available.
     *
     * @param array $message Inbound email data with bodyText and bodyHtml keys.
     * @return string The sanitized email body content.
     */
    public function get_sanitized_body( array $message ): string {
        // Prefer plain text body.
        if ( ! empty( $message['bodyText'] ) ) {
            $body = trim( $message['bodyText'] );
            if ( ! empty( $body ) ) {
                // Convert plain text line breaks to HTML paragraphs.
                return wpautop( esc_html( $body ) );
            }
        }

        // Fall back to HTML body.
        if ( ! empty( $message['bodyHtml'] ) ) {
            return wp_kses_post( $message['bodyHtml'] );
        }

        return '';
    }

    /**
     * Store raw inbound email attachments to the WordPress uploads directory.
     *
     * Unlike the AttachmentService::store() method which handles $_FILES uploads,
     * this method processes raw attachment data from inbound email parsers
     * (base64-encoded content or raw binary content).
     *
     * @param string $attachable_type The parent type (e.g., 'reply', 'ticket').
     * @param int    $attachable_id   The parent record ID.
     * @param array  $attachments     Array of attachment data arrays, each with keys:
     *                                filename, content (raw or base64), contentType, size.
     * @return array Array of created attachment records (false entries for failures).
     */
    public function store_inbound_attachments( string $attachable_type, int $attachable_id, array $attachments ): array {
        global $wpdb;

        $results    = [];
        $now        = current_time( 'mysql' );
        $table      = Escalated::table( 'attachments' );
        $blocked    = Enums::blocked_extensions();
        $max_size_kb = (int) Setting::get( 'max_attachment_size_kb', 10240 );
        $max_size_bytes = $max_size_kb * 1024;
        $max_attachments = (int) Setting::get( 'max_attachments_per_reply', 5 );
        $count = 0;

        // Get the WordPress uploads directory.
        $upload_dir = wp_upload_dir();
        $escalated_dir = $upload_dir['basedir'] . '/escalated/' . gmdate( 'Y/m' );

        // Ensure the directory exists.
        wp_mkdir_p( $escalated_dir );

        foreach ( $attachments as $attachment ) {
            if ( $count >= $max_attachments ) {
                break;
            }

            $filename = sanitize_file_name( $attachment['filename'] ?? 'unnamed' );
            $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

            // Check blocked extensions.
            if ( in_array( $extension, $blocked, true ) ) {
                $results[] = false;
                continue;
            }

            // Get the content.
            $content = $attachment['content'] ?? '';
            if ( empty( $content ) ) {
                $results[] = false;
                continue;
            }

            // Decode base64 if the content appears to be encoded.
            if ( ! empty( $attachment['encoding'] ) && strtolower( $attachment['encoding'] ) === 'base64' ) {
                $content = base64_decode( $content, true );
                if ( $content === false ) {
                    $results[] = false;
                    continue;
                }
            }

            // Check file size.
            $size = strlen( $content );
            if ( $size > $max_size_bytes ) {
                $results[] = false;
                continue;
            }

            // Generate a unique file path.
            $unique_filename = wp_unique_filename( $escalated_dir, $filename );
            $file_path = $escalated_dir . '/' . $unique_filename;

            // Write the file to disk.
            $written = file_put_contents( $file_path, $content );
            if ( $written === false ) {
                $results[] = false;
                continue;
            }

            // Set proper file permissions.
            chmod( $file_path, 0644 );

            // Determine MIME type.
            $mime_type = $attachment['contentType'] ?? '';
            if ( empty( $mime_type ) ) {
                $finfo = finfo_open( FILEINFO_MIME_TYPE );
                $mime_type = finfo_file( $finfo, $file_path );
                finfo_close( $finfo );
            }

            // Create the database record.
            $attachment_data = [
                'attachable_type'   => sanitize_text_field( $attachable_type ),
                'attachable_id'     => $attachable_id,
                'filename'          => $unique_filename,
                'original_filename' => $filename,
                'mime_type'         => sanitize_mime_type( $mime_type ),
                'size'              => $size,
                'path'              => $file_path,
                'created_at'        => $now,
            ];

            $result = $wpdb->insert( $table, $attachment_data );

            if ( $result !== false ) {
                $results[] = $wpdb->get_row(
                    $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id )
                );
            } else {
                // Clean up the file if DB insert fails.
                if ( file_exists( $file_path ) ) {
                    wp_delete_file( $file_path );
                }
                $results[] = false;
            }

            $count++;
        }

        return $results;
    }
}
