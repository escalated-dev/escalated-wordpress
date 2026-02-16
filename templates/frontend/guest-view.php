<?php
/**
 * Frontend template: Guest Ticket View
 *
 * Displays a ticket and conversation thread for a guest user accessing via token.
 *
 * Available variables:
 * @var object $ticket   The ticket object.
 * @var array  $replies  Array of reply objects.
 * @var bool   $is_guest Always true in this template.
 *
 * @package Escalated
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$statuses   = \Escalated\Helpers\Enums::ticket_statuses();
$priorities = \Escalated\Helpers\Enums::ticket_priorities();
$is_closed  = in_array( $ticket->status, [ 'resolved', 'closed' ], true );
?>

<div class="escalated-portal">
    <div class="escalated-notice escalated-notice--info">
        <?php esc_html_e( 'You are viewing this ticket as a guest. Bookmark this page to access your ticket later.', 'escalated' ); ?>
    </div>

    <div class="escalated-ticket-view">
        <!-- Ticket Header -->
        <div class="escalated-ticket-view-header">
            <h2><?php echo esc_html( $ticket->subject ); ?></h2>
            <div class="escalated-ticket-view-meta">
                <span>
                    <strong><?php esc_html_e( 'Ref:', 'escalated' ); ?></strong>
                    <?php echo esc_html( $ticket->reference ); ?>
                </span>
                <span>
                    <strong><?php esc_html_e( 'Status:', 'escalated' ); ?></strong>
                    <span class="escalated-badge escalated-badge--<?php echo esc_attr( $ticket->status ); ?>">
                        <?php echo esc_html( $statuses[ $ticket->status ]['label'] ?? $ticket->status ); ?>
                    </span>
                </span>
                <span>
                    <strong><?php esc_html_e( 'Priority:', 'escalated' ); ?></strong>
                    <span class="escalated-badge escalated-badge--<?php echo esc_attr( $ticket->priority ); ?>">
                        <?php echo esc_html( $priorities[ $ticket->priority ]['label'] ?? $ticket->priority ); ?>
                    </span>
                </span>
                <span>
                    <strong><?php esc_html_e( 'Created:', 'escalated' ); ?></strong>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $ticket->created_at ) ) ); ?>
                </span>
            </div>
        </div>

        <!-- Conversation Thread -->
        <div class="escalated-conversation">
            <!-- Original description -->
            <div class="escalated-reply">
                <div class="escalated-reply-header">
                    <span class="escalated-reply-author">
                        <?php echo esc_html( $ticket->guest_name ?: __( 'Guest', 'escalated' ) ); ?>
                        <span class="escalated-reply-role">(<?php esc_html_e( 'Author', 'escalated' ); ?>)</span>
                    </span>
                    <span class="escalated-reply-timestamp">
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $ticket->created_at ) ) ); ?>
                    </span>
                </div>
                <div class="escalated-reply-body">
                    <?php echo wp_kses_post( wpautop( $ticket->description ) ); ?>
                </div>
            </div>

            <?php foreach ( $replies as $reply ) :
                // Skip internal notes from guest view.
                if ( ! empty( $reply->is_internal_note ) ) {
                    continue;
                }

                $author      = ! empty( $reply->author_id ) ? get_userdata( (int) $reply->author_id ) : null;
                $is_agent    = false;
                if ( $author ) {
                    $agent_roles = [ 'escalated_agent', 'escalated_admin', 'administrator' ];
                    $is_agent    = ! empty( array_intersect( $author->roles, $agent_roles ) );
                }
                $reply_class = $is_agent ? 'escalated-reply escalated-reply--agent' : 'escalated-reply';
            ?>
                <div class="<?php echo esc_attr( $reply_class ); ?>">
                    <div class="escalated-reply-header">
                        <span class="escalated-reply-author">
                            <?php
                            if ( $is_agent && $author ) {
                                echo esc_html( $author->display_name );
                                echo ' <span class="escalated-reply-role">(' . esc_html__( 'Support', 'escalated' ) . ')</span>';
                            } else {
                                echo esc_html( $ticket->guest_name ?: __( 'Guest', 'escalated' ) );
                            }
                            ?>
                        </span>
                        <span class="escalated-reply-timestamp">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reply->created_at ) ) ); ?>
                        </span>
                    </div>
                    <div class="escalated-reply-body">
                        <?php echo wp_kses_post( wpautop( $reply->body ) ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( ! $is_closed ) : ?>
            <!-- Guest Reply Form -->
            <div class="escalated-reply-form">
                <h3><?php esc_html_e( 'Reply', 'escalated' ); ?></h3>
                <form class="escalated-guest-reply-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->id ); ?>" />
                    <input type="hidden" name="guest_token" value="<?php echo esc_attr( $ticket->guest_token ); ?>" />

                    <div class="escalated-form-group">
                        <label for="escalated-guest-reply-body" class="screen-reader-text">
                            <?php esc_html_e( 'Your reply', 'escalated' ); ?>
                        </label>
                        <textarea id="escalated-guest-reply-body" name="body" class="escalated-textarea" required
                                  placeholder="<?php esc_attr_e( 'Type your reply here...', 'escalated' ); ?>"></textarea>
                    </div>

                    <div class="escalated-form-group">
                        <label for="escalated-guest-reply-attachments">
                            <?php esc_html_e( 'Attachments', 'escalated' ); ?>
                        </label>
                        <input type="file" id="escalated-guest-reply-attachments" name="attachments[]" multiple
                               class="escalated-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip" />
                    </div>

                    <div class="escalated-form-group">
                        <button type="submit" class="escalated-btn escalated-btn--primary">
                            <?php esc_html_e( 'Send Reply', 'escalated' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php else : ?>
            <div class="escalated-notice escalated-notice--info" style="margin: 20px 24px;">
                <?php esc_html_e( 'This ticket is closed. No further replies can be added.', 'escalated' ); ?>
            </div>
        <?php endif; ?>
    </div>
</div>
