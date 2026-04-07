<?php
/**
 * Frontend template: Guest Ticket Creation
 *
 * Displays a ticket creation form for unauthenticated (guest) users.
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
?>

<div class="escalated-portal">
    <div class="escalated-guest-form">
        <h2><?php esc_html_e('Submit a Support Request', 'escalated'); ?></h2>
        <p style="color: var(--escalated-text-secondary); margin-bottom: 20px;">
            <?php esc_html_e('Fill out the form below to create a support ticket. You will receive a link to track your request.', 'escalated'); ?>
        </p>

        <form class="escalated-guest-create-form" method="post" enctype="multipart/form-data">
            <div class="escalated-guest-fields">
                <div class="escalated-form-group">
                    <label for="escalated-guest-name">
                        <?php esc_html_e('Your Name', 'escalated'); ?> <span class="required">*</span>
                    </label>
                    <input type="text" id="escalated-guest-name" name="guest_name" class="escalated-input" required
                           placeholder="<?php esc_attr_e('John Doe', 'escalated'); ?>" />
                </div>

                <div class="escalated-form-group">
                    <label for="escalated-guest-email">
                        <?php esc_html_e('Email Address', 'escalated'); ?> <span class="required">*</span>
                    </label>
                    <input type="email" id="escalated-guest-email" name="guest_email" class="escalated-input" required
                           placeholder="<?php esc_attr_e('you@example.com', 'escalated'); ?>" />
                </div>

                <div class="escalated-form-group escalated-form-group--full">
                    <label for="escalated-guest-subject">
                        <?php esc_html_e('Subject', 'escalated'); ?> <span class="required">*</span>
                    </label>
                    <input type="text" id="escalated-guest-subject" name="subject" class="escalated-input" required
                           placeholder="<?php esc_attr_e('Brief summary of your issue', 'escalated'); ?>" />
                </div>

                <div class="escalated-form-group escalated-form-group--full">
                    <label for="escalated-guest-description">
                        <?php esc_html_e('Description', 'escalated'); ?> <span class="required">*</span>
                    </label>
                    <textarea id="escalated-guest-description" name="description" class="escalated-textarea" required
                              placeholder="<?php esc_attr_e('Please describe your issue in detail...', 'escalated'); ?>"></textarea>
                </div>

                <?php if (! empty($departments)) { ?>
                    <div class="escalated-form-group escalated-form-group--full">
                        <label for="escalated-guest-department">
                            <?php esc_html_e('Department', 'escalated'); ?>
                        </label>
                        <select id="escalated-guest-department" name="department_id" class="escalated-select">
                            <option value=""><?php esc_html_e('-- Select Department --', 'escalated'); ?></option>
                            <?php foreach ($departments as $department) { ?>
                                <option value="<?php echo esc_attr($department->id); ?>">
                                    <?php echo esc_html($department->name); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>
            </div>

            <div class="escalated-form-group" style="margin-top: 24px;">
                <button type="submit" class="escalated-btn escalated-btn--primary">
                    <?php esc_html_e('Submit Request', 'escalated'); ?>
                </button>
            </div>
        </form>
    </div>
</div>
