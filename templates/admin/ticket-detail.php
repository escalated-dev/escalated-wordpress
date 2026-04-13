<?php
/**
 * Admin template: Ticket Detail
 *
 * @var object $ticket      The ticket object.
 * @var array $replies     Replies for this ticket.
 * @var array $activities  Activity log entries.
 * @var array $tags        Tags attached to this ticket.
 * @var array $all_tags    All available tags.
 * @var array $statuses    Ticket statuses from Enums.
 * @var array $priorities  Ticket priorities from Enums.
 * @var array $departments All departments.
 * @var array $agents      Agent user objects.
 * @var array $followers   Follower user objects.
 * @var array $attachments Ticket attachments.
 * @var object|null $sla_policy SLA policy if assigned.
 * @var string $message     Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

$status_info = $statuses[$ticket->status] ?? ['label' => ucfirst($ticket->status), 'color' => '#6B7280'];
$priority_info = $priorities[$ticket->priority] ?? ['label' => ucfirst($ticket->priority), 'color' => '#6B7280'];

// Requester name.
$requester_name = __('Guest', 'escalated');
$requester_email = '';
if ($ticket->requester_id) {
    $requester = get_userdata($ticket->requester_id);
    if ($requester) {
        $requester_name = $requester->display_name;
        $requester_email = $requester->user_email;
    }
} elseif (! empty($ticket->guest_name)) {
    $requester_name = $ticket->guest_name;
    $requester_email = $ticket->guest_email ?? '';
}

$nonce = wp_create_nonce('escalated_ticket_action_'.$ticket->id);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">
        <a href="<?php echo esc_url(admin_url('admin.php?page=escalated')); ?>">&larr; <?php esc_html_e('Tickets', 'escalated'); ?></a>
    </h1>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'reply_added' => __('Reply added successfully.', 'escalated'),
                    'note_added' => __('Internal note added.', 'escalated'),
                    'status_changed' => __('Status updated.', 'escalated'),
                    'priority_changed' => __('Priority updated.', 'escalated'),
                    'assigned' => __('Assignment updated.', 'escalated'),
                    'department_changed' => __('Department updated.', 'escalated'),
                    'tags_updated' => __('Tags updated.', 'escalated'),
                    'error' => __('An error occurred.', 'escalated'),
                ];
        echo esc_html($messages[$message] ?? __('Action completed.', 'escalated'));
        ?>
            </p>
        </div>
    <?php } ?>

    <!-- Ticket Header -->
    <div class="escalated-card" style="margin-bottom: 20px; padding: 15px;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <h2 style="margin: 0; font-size: 18px;">
                <span class="escalated-text-secondary"><?php echo esc_html($ticket->reference); ?></span>
                &mdash;
                <?php echo esc_html($ticket->subject); ?>
            </h2>
            <span class="escalated-badge" style="background-color: <?php echo esc_attr($status_info['color']); ?>; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 12px;">
                <?php echo esc_html($status_info['label']); ?>
            </span>
            <span class="escalated-badge" style="background-color: <?php echo esc_attr($priority_info['color']); ?>; color: #fff; padding: 4px 10px; border-radius: 3px; font-size: 12px;">
                <?php echo esc_html($priority_info['label']); ?>
            </span>
        </div>
        <p class="escalated-text-secondary" style="margin: 8px 0 0; font-size: 13px;">
            <?php
            /* translators: 1: requester name, 2: time ago */
            printf(
                esc_html__('Opened by %1$s %2$s ago', 'escalated'),
                '<strong>'.esc_html($requester_name).'</strong>',
                esc_html(human_time_diff(strtotime($ticket->created_at), current_time('timestamp')))
            );
if ($requester_email) {
    echo ' &middot; <a href="mailto:'.esc_attr($requester_email).'">'.esc_html($requester_email).'</a>';
}
?>
            &middot; <?php echo esc_html(ucfirst($ticket->channel)); ?>
        </p>
    </div>

    <div style="display: flex; gap: 20px; align-items: flex-start;">

        <!-- Main Content Area -->
        <div style="flex: 1; min-width: 0;">

            <!-- Ticket Description -->
            <div class="escalated-card" style="padding: 15px; margin-bottom: 15px;">
                <h3 style="margin-top: 0; font-size: 14px; border-bottom: 1px solid var(--esc-wp-border-light); padding-bottom: 8px;">
                    <?php esc_html_e('Description', 'escalated'); ?>
                </h3>
                <div class="escalated-ticket-body">
                    <?php echo wp_kses_post($ticket->description); ?>
                </div>
                <?php if (! empty($attachments)) { ?>
                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--esc-wp-border-light);">
                        <strong><?php esc_html_e('Attachments:', 'escalated'); ?></strong>
                        <ul style="margin: 5px 0 0; padding-left: 20px;">
                            <?php foreach ($attachments as $att) { ?>
                                <li>
                                    <a href="<?php echo esc_url(\Escalated\Services\AttachmentService::path_to_url($att->path) ?? '#'); ?>" target="_blank">
                                        <?php echo esc_html($att->original_filename); ?>
                                    </a>
                                    <span class="escalated-text-muted" style="font-size: 12px;">(<?php echo esc_html(size_format($att->size)); ?>)</span>
                                </li>
                            <?php } ?>
                        </ul>
                    </div>
                <?php } ?>
            </div>

            <!-- Conversation Thread -->
            <div style="margin-bottom: 15px;">
                <h3 style="font-size: 14px;"><?php esc_html_e('Conversation', 'escalated'); ?></h3>

                <?php if (empty($replies)) { ?>
                    <p class="escalated-text-secondary"><?php esc_html_e('No replies yet.', 'escalated'); ?></p>
                <?php } else { ?>
                    <?php foreach ($replies as $reply) {
                        $is_note = (bool) $reply->is_internal_note;
                        $author = $reply->author_id ? get_userdata($reply->author_id) : null;
                        $author_name = $author ? $author->display_name : __('System', 'escalated');

                        $reply_attachments = \Escalated\Models\Attachment::for_attachable('reply', $reply->id);

                        ?>
                        <div class="<?php echo $is_note ? 'escalated-reply-card--note' : 'escalated-reply-card'; ?>" style="border-left-width: 4px; padding: 12px 15px; margin-bottom: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div>
                                    <strong><?php echo esc_html($author_name); ?></strong>
                                    <?php if ($is_note) { ?>
                                        <span style="background: var(--esc-wp-amber); color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">
                                            <?php esc_html_e('Internal Note', 'escalated'); ?>
                                        </span>
                                    <?php } ?>
                                </div>
                                <span class="escalated-text-muted" style="font-size: 12px;" title="<?php echo esc_attr($reply->created_at); ?>">
                                    <?php echo esc_html(human_time_diff(strtotime($reply->created_at), current_time('timestamp')).' '.__('ago', 'escalated')); ?>
                                </span>
                            </div>
                            <div class="escalated-reply-body">
                                <?php echo wp_kses_post($reply->body); ?>
                            </div>
                            <?php if (! empty($reply_attachments)) { ?>
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--esc-wp-border-light);">
                                    <small><?php esc_html_e('Attachments:', 'escalated'); ?></small>
                                    <?php foreach ($reply_attachments as $att) { ?>
                                        <a href="<?php echo esc_url(\Escalated\Services\AttachmentService::path_to_url($att->path) ?? '#'); ?>" target="_blank" style="margin-left: 5px; font-size: 12px;">
                                            <?php echo esc_html($att->original_filename); ?>
                                        </a>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>

            <!-- Reply Form -->
            <?php if (current_user_can('escalated_reply_tickets')) { ?>
                <div class="escalated-card" style="padding: 15px; margin-bottom: 15px;">
                    <h3 style="margin-top: 0; font-size: 14px;"><?php esc_html_e('Add Reply', 'escalated'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="escalated_ticket_action" value="reply">
                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                        <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">

                        <?php
                            wp_editor('', 'reply_body', [
                                'textarea_name' => 'reply_body',
                                'media_buttons' => false,
                                'textarea_rows' => 8,
                                'teeny' => true,
                                'quicktags' => true,
                            ]);
                ?>

                        <p style="margin-top: 10px;">
                            <?php submit_button(__('Send Reply', 'escalated'), 'primary', 'submit_reply', false); ?>
                        </p>
                    </form>
                </div>
            <?php } ?>

            <!-- Internal Note Form -->
            <?php if (current_user_can('escalated_add_internal_notes')) { ?>
                <div class="escalated-note-card" style="padding: 15px; margin-bottom: 15px;">
                    <h3 class="escalated-text-warning-accent" style="margin-top: 0; font-size: 14px;"><?php esc_html_e('Add Internal Note', 'escalated'); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="escalated_ticket_action" value="note">
                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                        <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">

                        <?php
                wp_editor('', 'note_body', [
                    'textarea_name' => 'note_body',
                    'media_buttons' => false,
                    'textarea_rows' => 6,
                    'teeny' => true,
                    'quicktags' => true,
                ]);
                ?>

                        <p style="margin-top: 10px;">
                            <?php submit_button(__('Add Note', 'escalated'), 'secondary', 'submit_note', false); ?>
                        </p>
                    </form>
                </div>
            <?php } ?>

            <!-- Activity Timeline -->
            <div class="escalated-card" style="padding: 15px;">
                <h3 style="margin-top: 0; font-size: 14px;"><?php esc_html_e('Activity Timeline', 'escalated'); ?></h3>

                <?php if (empty($activities)) { ?>
                    <p class="escalated-text-secondary"><?php esc_html_e('No activity recorded.', 'escalated'); ?></p>
                <?php } else { ?>
                    <?php
                    $activity_labels = \Escalated\Helpers\Enums::activity_types();
                    ?>
                    <ul style="list-style: none; margin: 0; padding: 0;">
                        <?php foreach ($activities as $activity) {
                            $causer_name = __('System', 'escalated');
                            if ($activity->causer_id) {
                                $causer = get_userdata($activity->causer_id);
                                if ($causer) {
                                    $causer_name = $causer->display_name;
                                }
                            }
                            $type_label = $activity_labels[$activity->type] ?? ucfirst(str_replace('_', ' ', $activity->type));
                            $props = $activity->properties ? json_decode($activity->properties, true) : [];
                            ?>
                            <li style="padding: 8px 0; border-bottom: 1px solid var(--esc-wp-border-light); font-size: 13px;" class="escalated-text-secondary">
                                <span class="escalated-text-muted" style="font-size: 12px;"><?php echo esc_html($activity->created_at); ?></span>
                                &mdash;
                                <strong><?php echo esc_html($causer_name); ?></strong>:
                                <?php echo esc_html($type_label); ?>
                                <?php if (! empty($props)) { ?>
                                    <span class="escalated-text-muted">
                                        <?php
                                            $details = [];
                                    if (isset($props['old_status'])) {
                                        $details[] = $props['old_status'].' -> '.($props['new_status'] ?? '');
                                    } elseif (isset($props['new_status'])) {
                                        $details[] = $props['new_status'];
                                    }
                                    if (isset($props['old_priority'])) {
                                        $details[] = $props['old_priority'].' -> '.($props['new_priority'] ?? '');
                                    }
                                    if (isset($props['assigned_to'])) {
                                        $assigned_user = get_userdata((int) $props['assigned_to']);
                                        $details[] = $assigned_user ? $assigned_user->display_name : '#'.$props['assigned_to'];
                                    }
                                    if (! empty($details)) {
                                        echo '('.esc_html(implode(', ', $details)).')';
                                    }
                                    ?>
                                    </span>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>

        </div>

        <!-- Sidebar -->
        <div style="width: 300px; flex-shrink: 0;">

            <!-- Status -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Status', 'escalated'); ?></h4>
                <form method="post">
                    <input type="hidden" name="escalated_ticket_action" value="change_status">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                    <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <select name="new_status" style="width: 100%; margin-bottom: 5px;">
                        <?php foreach ($statuses as $key => $data) { ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($ticket->status, $key); ?>>
                                <?php echo esc_html($data['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php submit_button(__('Update', 'escalated'), 'small', 'submit', false); ?>
                </form>
            </div>

            <!-- Priority -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Priority', 'escalated'); ?></h4>
                <form method="post">
                    <input type="hidden" name="escalated_ticket_action" value="change_priority">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                    <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <select name="new_priority" style="width: 100%; margin-bottom: 5px;">
                        <?php foreach ($priorities as $key => $data) { ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($ticket->priority, $key); ?>>
                                <?php echo esc_html($data['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php submit_button(__('Update', 'escalated'), 'small', 'submit', false); ?>
                </form>
            </div>

            <!-- Assignee -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Assignee', 'escalated'); ?></h4>
                <form method="post">
                    <input type="hidden" name="escalated_ticket_action" value="assign">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                    <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <select name="assigned_to" style="width: 100%; margin-bottom: 5px;">
                        <option value="0"><?php esc_html_e('-- Unassigned --', 'escalated'); ?></option>
                        <?php foreach ($agents as $agent) { ?>
                            <option value="<?php echo esc_attr($agent->ID); ?>" <?php selected($ticket->assigned_to, $agent->ID); ?>>
                                <?php echo esc_html($agent->display_name); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php submit_button(__('Update', 'escalated'), 'small', 'submit', false); ?>
                </form>
            </div>

            <!-- Department -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Department', 'escalated'); ?></h4>
                <form method="post">
                    <input type="hidden" name="escalated_ticket_action" value="change_department">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                    <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <select name="department_id" style="width: 100%; margin-bottom: 5px;">
                        <option value=""><?php esc_html_e('-- None --', 'escalated'); ?></option>
                        <?php foreach ($departments as $dept) { ?>
                            <option value="<?php echo esc_attr($dept->id); ?>" <?php selected($ticket->department_id, $dept->id); ?>>
                                <?php echo esc_html($dept->name); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php submit_button(__('Update', 'escalated'), 'small', 'submit', false); ?>
                </form>
            </div>

            <!-- Tags -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Tags', 'escalated'); ?></h4>
                <?php if (! empty($tags)) { ?>
                    <div style="margin-bottom: 8px;">
                        <?php foreach ($tags as $tag) { ?>
                            <span style="display: inline-block; background: <?php echo esc_attr($tag->color); ?>; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin: 2px;">
                                <?php echo esc_html($tag->name); ?>
                            </span>
                        <?php } ?>
                    </div>
                <?php } ?>
                <form method="post">
                    <input type="hidden" name="escalated_ticket_action" value="update_tags">
                    <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket->id); ?>">
                    <input type="hidden" name="_escalated_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <select name="tag_ids[]" multiple style="width: 100%; min-height: 80px; margin-bottom: 5px;">
                        <?php
                        $current_tag_ids = array_map(function ($t) {
                            return $t->id;
                        }, $tags);
foreach ($all_tags as $tag) { ?>
                            <option value="<?php echo esc_attr($tag->id); ?>" <?php echo in_array($tag->id, $current_tag_ids, true) ? 'selected' : ''; ?>>
                                <?php echo esc_html($tag->name); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <?php submit_button(__('Update Tags', 'escalated'), 'small', 'submit', false); ?>
                </form>
            </div>

            <!-- SLA Info -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('SLA Information', 'escalated'); ?></h4>
                <?php if ($sla_policy) { ?>
                    <p style="margin: 0 0 5px; font-size: 13px;">
                        <strong><?php esc_html_e('Policy:', 'escalated'); ?></strong>
                        <?php echo esc_html($sla_policy->name); ?>
                    </p>
                <?php } ?>

                <p style="margin: 0 0 5px; font-size: 13px;">
                    <strong><?php esc_html_e('First Response:', 'escalated'); ?></strong>
                    <?php if ($ticket->first_response_at) { ?>
                        <span class="escalated-text-success"><?php echo esc_html($ticket->first_response_at); ?></span>
                    <?php } elseif ($ticket->first_response_due_at) { ?>
                        <?php
$due_ts = strtotime($ticket->first_response_due_at);
                        $now_ts = current_time('timestamp');
                        $is_overdue = $now_ts > $due_ts;
                        ?>
                        <span class="<?php echo $is_overdue ? 'escalated-text-danger' : 'escalated-text-warning'; ?>">
                            <?php
                            if ($is_overdue) {
                                /* translators: %s: time elapsed */
                                printf(esc_html__('Overdue by %s', 'escalated'), esc_html(human_time_diff($due_ts, $now_ts)));
                            } else {
                                /* translators: %s: time remaining */
                                printf(esc_html__('Due in %s', 'escalated'), esc_html(human_time_diff($now_ts, $due_ts)));
                            }
                        ?>
                        </span>
                    <?php } else { ?>
                        <span class="escalated-text-muted"><?php esc_html_e('N/A', 'escalated'); ?></span>
                    <?php } ?>
                </p>

                <p style="margin: 0 0 5px; font-size: 13px;">
                    <strong><?php esc_html_e('Resolution Due:', 'escalated'); ?></strong>
                    <?php if ($ticket->resolved_at) { ?>
                        <span class="escalated-text-success"><?php echo esc_html($ticket->resolved_at); ?></span>
                    <?php } elseif ($ticket->resolution_due_at) { ?>
                        <?php
                        $due_ts = strtotime($ticket->resolution_due_at);
                        $now_ts = current_time('timestamp');
                        $is_overdue = $now_ts > $due_ts;
                        ?>
                        <span class="<?php echo $is_overdue ? 'escalated-text-danger' : 'escalated-text-warning'; ?>">
                            <?php
                            if ($is_overdue) {
                                printf(esc_html__('Overdue by %s', 'escalated'), esc_html(human_time_diff($due_ts, $now_ts)));
                            } else {
                                printf(esc_html__('Due in %s', 'escalated'), esc_html(human_time_diff($now_ts, $due_ts)));
                            }
                        ?>
                        </span>
                    <?php } else { ?>
                        <span class="escalated-text-muted"><?php esc_html_e('N/A', 'escalated'); ?></span>
                    <?php } ?>
                </p>

                <?php if ($ticket->sla_first_response_breached || $ticket->sla_resolution_breached) { ?>
                    <p class="escalated-sla-breach-alert" style="margin: 5px 0 0; padding: 5px 8px; border-radius: 3px; font-size: 12px;">
                        <?php if ($ticket->sla_first_response_breached) { ?>
                            <?php esc_html_e('First response SLA breached', 'escalated'); ?><br>
                        <?php } ?>
                        <?php if ($ticket->sla_resolution_breached) { ?>
                            <?php esc_html_e('Resolution SLA breached', 'escalated'); ?>
                        <?php } ?>
                    </p>
                <?php } ?>
            </div>

            <!-- Followers -->
            <div class="escalated-card" style="padding: 12px; margin-bottom: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Followers', 'escalated'); ?></h4>
                <?php if (empty($followers)) { ?>
                    <p class="escalated-text-muted" style="font-size: 13px; margin: 0;"><?php esc_html_e('No followers.', 'escalated'); ?></p>
                <?php } else { ?>
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        <?php foreach ($followers as $follower) { ?>
                            <li style="padding: 3px 0; font-size: 13px;">
                                <?php echo get_avatar($follower->ID, 20, '', '', ['style' => 'vertical-align: middle; margin-right: 5px; border-radius: 50%;']); ?>
                                <?php echo esc_html($follower->display_name); ?>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>

            <!-- Ticket Metadata -->
            <div class="escalated-card" style="padding: 12px;">
                <h4 class="escalated-text-primary" style="margin: 0 0 8px; font-size: 13px;"><?php esc_html_e('Details', 'escalated'); ?></h4>
                <table style="width: 100%; font-size: 13px;" cellpadding="3">
                    <tr>
                        <td class="escalated-text-secondary"><?php esc_html_e('Created:', 'escalated'); ?></td>
                        <td><?php echo esc_html($ticket->created_at); ?></td>
                    </tr>
                    <tr>
                        <td class="escalated-text-secondary"><?php esc_html_e('Updated:', 'escalated'); ?></td>
                        <td><?php echo esc_html($ticket->updated_at); ?></td>
                    </tr>
                    <?php if ($ticket->resolved_at) { ?>
                    <tr>
                        <td class="escalated-text-secondary"><?php esc_html_e('Resolved:', 'escalated'); ?></td>
                        <td><?php echo esc_html($ticket->resolved_at); ?></td>
                    </tr>
                    <?php } ?>
                    <?php if ($ticket->closed_at) { ?>
                    <tr>
                        <td class="escalated-text-secondary"><?php esc_html_e('Closed:', 'escalated'); ?></td>
                        <td><?php echo esc_html($ticket->closed_at); ?></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>

        </div>
    </div>
</div>
