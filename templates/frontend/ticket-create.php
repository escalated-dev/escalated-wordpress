<?php
/**
 * Frontend template: Create Ticket
 *
 * Displays the ticket creation form for authenticated users.
 *
 * Available variables:
 *
 * @var array $departments Array of department objects (id, name).
 *
 * @since   1.0.0
 */
if (! defined('ABSPATH')) {
    exit;
}

$priorities = \Escalated\Helpers\Enums::ticket_priorities();
?>

<div class="escalated-portal">
    <div class="escalated-actions-bar">
        <a href="<?php echo esc_url(remove_query_arg('action')); ?>" class="escalated-back-link">
            &larr; <?php esc_html_e('Back to My Tickets', 'escalated'); ?>
        </a>
    </div>

    <div class="escalated-ticket-form">
        <h2><?php esc_html_e('Create a New Ticket', 'escalated'); ?></h2>

        <form class="escalated-create-form" method="post" enctype="multipart/form-data">
            <div class="escalated-form-group">
                <label for="escalated-subject">
                    <?php esc_html_e('Subject', 'escalated'); ?> <span class="required">*</span>
                </label>
                <input type="text" id="escalated-subject" name="subject" class="escalated-input" required
                       placeholder="<?php esc_attr_e('Brief summary of your issue', 'escalated'); ?>" />
            </div>

            <div class="escalated-form-group">
                <label for="escalated-description">
                    <?php esc_html_e('Description', 'escalated'); ?> <span class="required">*</span>
                </label>
                <textarea id="escalated-description" name="description" class="escalated-textarea" required
                          placeholder="<?php esc_attr_e('Describe your issue in detail...', 'escalated'); ?>"></textarea>
            </div>

            <?php if (! empty($departments)) { ?>
                <div class="escalated-form-group">
                    <label for="escalated-department">
                        <?php esc_html_e('Department', 'escalated'); ?>
                    </label>
                    <select id="escalated-department" name="department_id" class="escalated-select">
                        <option value=""><?php esc_html_e('-- Select Department --', 'escalated'); ?></option>
                        <?php foreach ($departments as $department) { ?>
                            <option value="<?php echo esc_attr($department->id); ?>">
                                <?php echo esc_html($department->name); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
            <?php } ?>

            <div class="escalated-form-group">
                <label for="escalated-priority">
                    <?php esc_html_e('Priority', 'escalated'); ?>
                </label>
                <select id="escalated-priority" name="priority" class="escalated-select">
                    <?php foreach ($priorities as $key => $meta) { ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected($key, 'medium'); ?>>
                            <?php echo esc_html($meta['label']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="escalated-form-group">
                <label for="escalated-attachments">
                    <?php esc_html_e('Attachments', 'escalated'); ?>
                </label>
                <input type="file" id="escalated-attachments" name="attachments[]" multiple
                       class="escalated-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,.zip" />
                <p style="margin-top: 4px; font-size: 13px; color: var(--escalated-text-secondary);">
                    <?php esc_html_e('You can attach up to 5 files. Max 10 MB each.', 'escalated'); ?>
                </p>
            </div>

            <div class="escalated-form-group" style="margin-top: 24px;">
                <button type="submit" class="escalated-btn escalated-btn--primary">
                    <?php esc_html_e('Submit Ticket', 'escalated'); ?>
                </button>
            </div>
        </form>
    </div>
</div>
