<?php
/**
 * Admin template: Business Hours
 *
 * @var array $schedules All business schedule rows.
 * @var array $holiday_counts Holiday count per schedule id.
 * @var object|null $edit_item Schedule being edited (or null).
 * @var array $edit_hours Weekly hours shown in the form.
 * @var array $edit_holidays Holidays shown in the form.
 * @var array $timezones IANA time zone names.
 * @var array $weekdays Weekday keys, Monday first.
 * @var array $time_options Times offered for opening and closing.
 * @var string $message Flash message key.
 */
if (! defined('ABSPATH')) {
    exit;
}

$messages = [
    'created' => ['success', __('Business schedule created.', 'escalated')],
    'updated' => ['success', __('Business schedule updated.', 'escalated')],
    'deleted' => ['success', __('Business schedule deleted.', 'escalated')],
    'missing_name' => ['error', __('Enter a name of up to 255 characters.', 'escalated')],
    'invalid_timezone' => ['error', __('Choose a time zone from the list.', 'escalated')],
    'invalid_hours' => ['error', __('Give each open day both an opening and a closing time.', 'escalated')],
    'invalid_holiday' => ['error', __('Give each holiday a name and a valid date.', 'escalated')],
    'error' => ['error', __('An error occurred. Please try again.', 'escalated')],
];
$day_labels = [
    'monday' => __('Monday', 'escalated'),
    'tuesday' => __('Tuesday', 'escalated'),
    'wednesday' => __('Wednesday', 'escalated'),
    'thursday' => __('Thursday', 'escalated'),
    'friday' => __('Friday', 'escalated'),
    'saturday' => __('Saturday', 'escalated'),
    'sunday' => __('Sunday', 'escalated'),
];
$can_manage = current_user_can('escalated_sla_manage');
$holiday_rows = array_merge(
    array_map(static fn ($h) => ['name' => $h->name, 'date' => substr((string) $h->date, 0, 10), 'recurring' => (int) $h->recurring], $edit_holidays),
    array_fill(0, 3, ['name' => '', 'date' => '', 'recurring' => 0])
);
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Business Hours', 'escalated'); ?></h1>
    <hr class="wp-header-end">

    <?php if ($message && isset($messages[$message])) { ?>
        <div class="notice notice-<?php echo esc_attr($messages[$message][0]); ?> is-dismissible">
            <p><?php echo esc_html($messages[$message][1]); ?></p>
        </div>
    <?php } ?>

    <p class="description">
        <?php esc_html_e('SLA policies limited to business hours count their deadlines on a schedule: the policy\'s own, else the ticket department\'s, else the default one. Without any schedule the fixed business hours apply.', 'escalated'); ?>
    </p>

    <table class="wp-list-table widefat fixed striped" style="margin: 15px 0 20px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                <th scope="col"><?php esc_html_e('Time zone', 'escalated'); ?></th>
                <th scope="col" style="width: 90px;"><?php esc_html_e('Default', 'escalated'); ?></th>
                <th scope="col" style="width: 90px;"><?php esc_html_e('Holidays', 'escalated'); ?></th>
                <th scope="col" style="width: 160px;"><?php esc_html_e('Actions', 'escalated'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($schedules)) { ?>
                <tr>
                    <td colspan="5"><?php esc_html_e('No business schedules yet.', 'escalated'); ?></td>
                </tr>
            <?php } else { ?>
                <?php foreach ($schedules as $schedule) { ?>
                    <tr>
                        <td><strong><?php echo esc_html($schedule->name); ?></strong></td>
                        <td><?php echo esc_html($schedule->timezone); ?></td>
                        <td><?php echo (int) $schedule->is_default ? esc_html__('Yes', 'escalated') : '&mdash;'; ?></td>
                        <td><?php echo esc_html((string) ($holiday_counts[(int) $schedule->id] ?? 0)); ?></td>
                        <td>
                            <?php if ($can_manage) { ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-business-hours&action=edit&id='.$schedule->id)); ?>" class="button button-small"><?php esc_html_e('Edit', 'escalated'); ?></a>
                                <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this schedule? Policies and departments using it fall back to the default schedule.', 'escalated')); ?>');">
                                    <input type="hidden" name="escalated_business_hours_action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($schedule->id); ?>">
                                    <?php wp_nonce_field('escalated_business_hours_delete_'.$schedule->id, '_escalated_nonce'); ?>
                                    <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'escalated'); ?></button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>

    <?php if ($can_manage) { ?>
    <div class="escalated-card" style="padding: 15px; max-width: 800px;">
        <h2 style="margin-top: 0; font-size: 15px;">
            <?php echo $edit_item ? esc_html__('Edit Schedule', 'escalated') : esc_html__('Add Schedule', 'escalated'); ?>
        </h2>

        <form method="post">
            <?php if ($edit_item) { ?>
                <input type="hidden" name="escalated_business_hours_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr($edit_item->id); ?>">
                <?php wp_nonce_field('escalated_business_hours_update_'.$edit_item->id, '_escalated_nonce'); ?>
            <?php } else { ?>
                <input type="hidden" name="escalated_business_hours_action" value="create">
                <?php wp_nonce_field('escalated_business_hours_create', '_escalated_nonce'); ?>
            <?php } ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="bh-name"><?php esc_html_e('Name', 'escalated'); ?></label></th>
                    <td>
                        <input type="text" id="bh-name" name="name" class="regular-text" required maxlength="255"
                               value="<?php echo esc_attr($edit_item->name ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bh-timezone"><?php esc_html_e('Time zone', 'escalated'); ?></label></th>
                    <td>
                        <?php
                        $current_timezone = $edit_item->timezone ?? wp_timezone_string();
        if (! in_array($current_timezone, $timezones, true)) {
            $current_timezone = 'UTC';
        }
        ?>
                        <select id="bh-timezone" name="timezone" required>
                            <?php foreach ($timezones as $tz) { ?>
                                <option value="<?php echo esc_attr($tz); ?>"<?php selected($current_timezone, $tz); ?>><?php echo esc_html($tz); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Default', 'escalated'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_default" value="1" <?php checked($edit_item ? (int) $edit_item->is_default : 0, 1); ?>>
                            <?php esc_html_e('Use for policies and departments without their own schedule', 'escalated'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Weekly hours', 'escalated'); ?></th>
                    <td>
                        <table class="widefat" style="max-width: 480px;">
                            <tbody>
                                <?php foreach ($weekdays as $day) { ?>
                                    <?php $day_hours = is_array($edit_hours[$day] ?? null) ? $edit_hours[$day] : ['start' => '', 'end' => '']; ?>
                                    <tr>
                                        <td style="width: 120px;"><?php echo esc_html($day_labels[$day]); ?></td>
                                        <?php foreach (['start' => __('Opens', 'escalated'), 'end' => __('Closes', 'escalated')] as $field => $label) { ?>
                                            <?php
                            $value = (string) ($day_hours[$field] ?? '');
                                            if (($day_hours['enabled'] ?? true) === false) {
                                                $value = '';
                                            }
                                            $options = $time_options;
                                            if ($value !== '' && ! in_array($value, $options, true)) {
                                                $options[] = $value;
                                                sort($options);
                                            }
                                            ?>
                                            <td>
                                                <label class="screen-reader-text" for="<?php echo esc_attr('bh-'.$day.'-'.$field); ?>"><?php echo esc_html($day_labels[$day].' '.$label); ?></label>
                                                <select id="<?php echo esc_attr('bh-'.$day.'-'.$field); ?>" name="<?php echo esc_attr('schedule['.$day.']['.$field.']'); ?>">
                                                    <option value=""><?php esc_html_e('Closed', 'escalated'); ?></option>
                                                    <?php foreach ($options as $time) { ?>
                                                        <option value="<?php echo esc_attr($time); ?>"<?php selected($value, $time); ?>><?php echo esc_html($time); ?></option>
                                                    <?php } ?>
                                                </select>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                        <p class="description"><?php esc_html_e('Times are in the schedule\'s time zone. A closing time before the opening time runs past midnight; 24:00 is midnight at the end of the day.', 'escalated'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Holidays', 'escalated'); ?></th>
                    <td>
                        <table class="widefat" style="max-width: 640px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Name', 'escalated'); ?></th>
                                    <th><?php esc_html_e('Date', 'escalated'); ?></th>
                                    <th><?php esc_html_e('Every year', 'escalated'); ?></th>
                                    <th><?php esc_html_e('Remove', 'escalated'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($holiday_rows as $i => $holiday) { ?>
                                    <tr>
                                        <td><input type="text" name="<?php echo esc_attr('holidays['.$i.'][name]'); ?>" maxlength="255" value="<?php echo esc_attr($holiday['name']); ?>" aria-label="<?php esc_attr_e('Holiday name', 'escalated'); ?>"></td>
                                        <td><input type="date" name="<?php echo esc_attr('holidays['.$i.'][date]'); ?>" value="<?php echo esc_attr($holiday['date']); ?>" aria-label="<?php esc_attr_e('Holiday date', 'escalated'); ?>"></td>
                                        <td><input type="checkbox" name="<?php echo esc_attr('holidays['.$i.'][recurring]'); ?>" value="1" <?php checked($holiday['recurring'], 1); ?> aria-label="<?php esc_attr_e('Every year', 'escalated'); ?>"></td>
                                        <td>
                                            <?php if ($holiday['name'] !== '') { ?>
                                                <input type="checkbox" name="<?php echo esc_attr('holidays['.$i.'][remove]'); ?>" value="1" aria-label="<?php esc_attr_e('Remove holiday', 'escalated'); ?>">
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                        <p class="description"><?php esc_html_e('No business time is counted on a holiday. Leave a row blank to skip it; save to get more rows.', 'escalated'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button($edit_item ? __('Update Schedule', 'escalated') : __('Add Schedule', 'escalated')); ?>
            <?php if ($edit_item) { ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-business-hours')); ?>"><?php esc_html_e('Cancel', 'escalated'); ?></a>
            <?php } ?>
        </form>
    </div>
    <?php } ?>
</div>
