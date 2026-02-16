<?php
/**
 * Frontend template: Single Ticket View
 *
 * Displays ticket details, conversation thread, reply form, and satisfaction rating.
 *
 * Available variables:
 * @var object      $ticket   The ticket object.
 * @var array       $replies  Array of reply objects.
 * @var array       $tags     Array of tag objects.
 * @var object|null $rating   Existing satisfaction rating, or null.
 * @var bool        $is_guest Whether this is a guest view.
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
    <div class="escalated-actions-bar">
        <?php if ( ! $is_guest ) : ?>
            <a href="<?php echo esc_url( remove_query_arg( 'ticket' ) ); ?>" class="escalated-back-link">
                &larr; <?php esc_html_e( 'Back to My Tickets', 'escalated' ); ?>
            </a>
        <?php endif; ?>

        <?php if ( ! $is_closed && ! $is_guest ) : ?>
            <button class="escalated-btn escalated-btn--danger escalated-close-ticket-btn"
                    data-ticket-id="<?php echo esc_attr( $ticket->id ); ?>">
                <?php esc_html_e( 'Close Ticket', 'escalated' ); ?>
            </button>
        <?php endif; ?>
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

            <?php if ( ! empty( $tags ) ) : ?>
                <div style="margin-top: 10px; display: flex; gap: 6px; flex-wrap: wrap;">
                    <?php foreach ( $tags as $tag ) : ?>
                        <span class="escalated-badge" style="background-color: <?php echo esc_attr( $tag->color ?? '#6B7280' ); ?>;">
                            <?php echo esc_html( $tag->name ); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Conversation Thread -->
        <div class="escalated-conversation">
            <!-- Original description as the first message -->
            <div class="escalated-reply">
                <div class="escalated-reply-header">
                    <span class="escalated-reply-author">
                        <?php
                        if ( ! empty( $ticket->requester_id ) ) {
                            $requester = get_userdata( (int) $ticket->requester_id );
                            echo esc_html( $requester ? $requester->display_name : __( 'Unknown', 'escalated' ) );
                        } elseif ( ! empty( $ticket->guest_name ) ) {
                            echo esc_html( $ticket->guest_name );
                        } else {
                            esc_html_e( 'Guest', 'escalated' );
                        }
                        ?>
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
                // Skip internal notes for non-admin/guest views.
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
                            <?php echo esc_html( $author ? $author->display_name : ( $ticket->guest_name ?? __( 'Guest', 'escalated' ) ) ); ?>
                            <?php if ( $is_agent ) : ?>
                                <span class="escalated-reply-role">(<?php esc_html_e( 'Support', 'escalated' ); ?>)</span>
                            <?php endif; ?>
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
            <!-- Reply Form -->
            <div class="escalated-reply-form">
                <h3><?php esc_html_e( 'Reply', 'escalated' ); ?></h3>
                <form class="escalated-reply-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->id ); ?>" />

                    <div class="escalated-form-group">
                        <label for="escalated-reply-body" class="screen-reader-text">
                            <?php esc_html_e( 'Your reply', 'escalated' ); ?>
                        </label>
                        <textarea id="escalated-reply-body" name="body" class="escalated-textarea" required
                                  placeholder="<?php esc_attr_e( 'Type your reply here...', 'escalated' ); ?>"></textarea>
                    </div>

                    <div class="escalated-form-group">
                        <label for="escalated-reply-attachments">
                            <?php esc_html_e( 'Attachments', 'escalated' ); ?>
                        </label>
                        <input type="file" id="escalated-reply-attachments" name="attachments[]" multiple
                               class="escalated-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip" />
                    </div>

                    <div class="escalated-form-group">
                        <button type="submit" class="escalated-btn escalated-btn--primary">
                            <?php esc_html_e( 'Send Reply', 'escalated' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php
        // Satisfaction Rating - show only for resolved/closed tickets without an existing rating.
        if ( $is_closed && empty( $rating ) && ! $is_guest ) :
        ?>
            <div class="escalated-rating">
                <h3><?php esc_html_e( 'How was your experience?', 'escalated' ); ?></h3>
                <p><?php esc_html_e( 'Please rate the support you received for this ticket.', 'escalated' ); ?></p>

                <form class="escalated-rating-form" method="post">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->id ); ?>" />
                    <input type="hidden" name="rating" value="" />

                    <div class="escalated-stars">
                        <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <span class="escalated-star" data-value="<?php echo esc_attr( $i ); ?>"></span>
                        <?php endfor; ?>
                    </div>

                    <div class="escalated-rating-comment">
                        <label for="escalated-rating-comment">
                            <?php esc_html_e( 'Comments (optional)', 'escalated' ); ?>
                        </label>
                        <textarea id="escalated-rating-comment" name="comment" class="escalated-textarea"
                                  rows="3"
                                  placeholder="<?php esc_attr_e( 'Tell us more about your experience...', 'escalated' ); ?>"></textarea>
                    </div>

                    <div style="margin-top: 12px;">
                        <button type="submit" class="escalated-btn escalated-btn--primary">
                            <?php esc_html_e( 'Submit Rating', 'escalated' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php elseif ( $is_closed && ! empty( $rating ) && ! $is_guest ) : ?>
            <div class="escalated-rating">
                <h3><?php esc_html_e( 'Your Rating', 'escalated' ); ?></h3>
                <div class="escalated-stars">
                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                        <span class="escalated-star <?php echo $i <= (int) $rating->rating ? 'active' : ''; ?>"></span>
                    <?php endfor; ?>
                </div>
                <?php if ( ! empty( $rating->comment ) ) : ?>
                    <p style="margin-top: 8px; font-style: italic; color: var(--escalated-text-secondary);">
                        <?php echo esc_html( $rating->comment ); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
