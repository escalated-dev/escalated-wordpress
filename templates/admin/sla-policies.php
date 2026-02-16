<?php
/**
 * Admin template: SLA Policies
 *
 * @var array       $policies   All SLA policy objects.
 * @var object|null $edit_item  Policy being edited (or null).
 * @var array       $priorities Ticket priorities from Enums.
 * @var string      $message    Flash message key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$edit_first_response = [];
$edit_resolution     = [];
if ( $edit_item ) {
    $edit_first_response = json_decode( $edit_item->first_response_hours ?? '{}', true ) ?: [];
    $edit_resolution     = json_decode( $edit_item->resolution_hours ?? '{}', true ) ?: [];
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'SLA Policies', 'escalated' ); ?></h1>
    <hr class="wp-header-end">

    <?php if ( $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __( 'SLA policy created successfully.', 'escalated' ),
                    'updated' => __( 'SLA policy updated successfully.', 'escalated' ),
                    'deleted' => __( 'SLA policy deleted successfully.', 'escalated' ),
                    'error'   => __( 'An error occurred. Please try again.', 'escalated' ),
                ];
                echo esc_html( $messages[ $message ] ?? __( 'Action completed.', 'escalated' ) );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- List -->
    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'Name', 'escalated' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Description', 'escalated' ); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e( 'Default', 'escalated' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'Business Hours', 'escalated' ); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e( 'Active', 'escalated' ); ?></th>
                <th scope="col" style="width: 150px;"><?php esc_html_e( 'Actions', 'escalated' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $policies ) ) : ?>
                <tr>
                    <td colspan="6"><?php esc_html_e( 'No SLA policies found.', 'escalated' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $policies as $policy ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $policy->name ); ?></strong></td>
                        <td><?php echo esc_html( wp_trim_words( $policy->description ?? '', 15 ) ); ?></td>
                        <td>
                            <?php if ( $policy->is_default ) : ?>
                                <span style="color: #10B981; font-weight: 600;"><?php esc_html_e( 'Yes', 'escalated' ); ?></span>
                            <?php else : ?>
                                <span style="color: #999;"><?php esc_html_e( 'No', 'escalated' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $policy->business_hours_only ? esc_html__( 'Yes', 'escalated' ) : esc_html__( 'No', 'escalated' ); ?>
                        </td>
                        <td>
                            <?php if ( $policy->is_active ) : ?>
                                <span style="color: #10B981; font-weight: 600;"><?php esc_html_e( 'Yes', 'escalated' ); ?></span>
                            <?php else : ?>
                                <span style="color: #EF4444;"><?php esc_html_e( 'No', 'escalated' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=escalated-sla-policies&action=edit&id=' . $policy->id ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'Edit', 'escalated' ); ?>
                            </a>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this SLA policy?', 'escalated' ) ); ?>');">
                                <input type="hidden" name="escalated_sla_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $policy->id ); ?>">
                                <?php wp_nonce_field( 'escalated_sla_delete_' . $policy->id, '_escalated_nonce' ); ?>
                                <button type="submit" class="button button-small button-link-delete">
                                    <?php esc_html_e( 'Delete', 'escalated' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Form -->
    <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px; max-width: 800px;">
        <h2 style="margin-top: 0; font-size: 15px;">
            <?php echo $edit_item ? esc_html__( 'Edit SLA Policy', 'escalated' ) : esc_html__( 'Add New SLA Policy', 'escalated' ); ?>
        </h2>

        <form method="post">
            <?php if ( $edit_item ) : ?>
                <input type="hidden" name="escalated_sla_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr( $edit_item->id ); ?>">
                <?php wp_nonce_field( 'escalated_sla_update_' . $edit_item->id, '_escalated_nonce' ); ?>
            <?php else : ?>
                <input type="hidden" name="escalated_sla_action" value="create">
                <?php wp_nonce_field( 'escalated_sla_create', '_escalated_nonce' ); ?>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="sla-name"><?php esc_html_e( 'Name', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="sla-name" name="name" class="regular-text" required
                               value="<?php echo esc_attr( $edit_item->name ?? '' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sla-description"><?php esc_html_e( 'Description', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <textarea id="sla-description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $edit_item->description ?? '' ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Options', 'escalated' ); ?></th>
                    <td>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="is_default" value="1"
                                <?php checked( $edit_item->is_default ?? 0, 1 ); ?>>
                            <?php esc_html_e( 'Set as default policy', 'escalated' ); ?>
                        </label>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox" name="business_hours_only" value="1"
                                <?php checked( $edit_item->business_hours_only ?? 0, 1 ); ?>>
                            <?php esc_html_e( 'Calculate SLA during business hours only', 'escalated' ); ?>
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" name="is_active" value="1"
                                <?php checked( $edit_item ? $edit_item->is_active : 1, 1 ); ?>>
                            <?php esc_html_e( 'Policy is active', 'escalated' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <!-- SLA Hours Grid -->
            <h3 style="font-size: 14px;"><?php esc_html_e( 'SLA Targets (Hours)', 'escalated' ); ?></h3>
            <table class="wp-list-table widefat" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Priority', 'escalated' ); ?></th>
                        <th style="width: 150px;"><?php esc_html_e( 'First Response (hrs)', 'escalated' ); ?></th>
                        <th style="width: 150px;"><?php esc_html_e( 'Resolution (hrs)', 'escalated' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $priorities as $key => $priority_data ) : ?>
                        <tr>
                            <td>
                                <span style="display: inline-block; width: 12px; height: 12px; background: <?php echo esc_attr( $priority_data['color'] ); ?>; border-radius: 2px; vertical-align: middle; margin-right: 5px;"></span>
                                <?php echo esc_html( $priority_data['label'] ); ?>
                            </td>
                            <td>
                                <input type="number" name="first_response_hours[<?php echo esc_attr( $key ); ?>]"
                                       value="<?php echo esc_attr( $edit_first_response[ $key ] ?? '' ); ?>"
                                       min="0" step="1" class="small-text" style="width: 80px;">
                            </td>
                            <td>
                                <input type="number" name="resolution_hours[<?php echo esc_attr( $key ); ?>]"
                                       value="<?php echo esc_attr( $edit_resolution[ $key ] ?? '' ); ?>"
                                       min="0" step="1" class="small-text" style="width: 80px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 15px;">
                <?php
                submit_button(
                    $edit_item ? __( 'Update Policy', 'escalated' ) : __( 'Add Policy', 'escalated' ),
                    'primary',
                    'submit',
                    false
                );
                ?>
                <?php if ( $edit_item ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=escalated-sla-policies' ) ); ?>" class="button" style="margin-left: 5px;">
                        <?php esc_html_e( 'Cancel', 'escalated' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </form>
    </div>
</div>
