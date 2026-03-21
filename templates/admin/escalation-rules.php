<?php
/**
 * Admin template: Escalation Rules
 *
 * @var array       $rules     All escalation rule objects.
 * @var object|null $edit_item Rule being edited (or null).
 * @var string      $message   Flash message key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$edit_conditions = [];
$edit_actions    = [];
if ( $edit_item ) {
    $edit_conditions = json_decode( $edit_item->conditions ?? '[]', true ) ?: [];
    $edit_actions    = json_decode( $edit_item->actions ?? '[]', true ) ?: [];
}

$trigger_types = [
    'time_based'     => __( 'Time Based', 'escalated' ),
    'sla_breach'     => __( 'SLA Breach', 'escalated' ),
    'status_change'  => __( 'Status Change', 'escalated' ),
    'no_response'    => __( 'No Response', 'escalated' ),
    'priority_based' => __( 'Priority Based', 'escalated' ),
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Escalation Rules', 'escalated' ); ?></h1>
    <hr class="wp-header-end">

    <?php if ( $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __( 'Escalation rule created successfully.', 'escalated' ),
                    'updated' => __( 'Escalation rule updated successfully.', 'escalated' ),
                    'deleted' => __( 'Escalation rule deleted successfully.', 'escalated' ),
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
                <th scope="col" style="width: 50px;"><?php esc_html_e( 'Order', 'escalated' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Name', 'escalated' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Description', 'escalated' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'Trigger', 'escalated' ); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e( 'Active', 'escalated' ); ?></th>
                <th scope="col" style="width: 150px;"><?php esc_html_e( 'Actions', 'escalated' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $rules ) ) : ?>
                <tr>
                    <td colspan="6"><?php esc_html_e( 'No escalation rules found.', 'escalated' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $rules as $rule ) : ?>
                    <tr>
                        <td><?php echo esc_html( $rule->sort_order ?? 0 ); ?></td>
                        <td><strong><?php echo esc_html( $rule->name ); ?></strong></td>
                        <td><?php echo esc_html( wp_trim_words( $rule->description ?? '', 15 ) ); ?></td>
                        <td>
                            <?php echo esc_html( $trigger_types[ $rule->trigger_type ] ?? ucfirst( str_replace( '_', ' ', $rule->trigger_type ?? '' ) ) ); ?>
                        </td>
                        <td>
                            <?php if ( $rule->is_active ) : ?>
                                <span class="escalated-text-success"><?php esc_html_e( 'Yes', 'escalated' ); ?></span>
                            <?php else : ?>
                                <span class="escalated-text-danger"><?php esc_html_e( 'No', 'escalated' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=escalated-escalation-rules&action=edit&id=' . $rule->id ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'Edit', 'escalated' ); ?>
                            </a>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this rule?', 'escalated' ) ); ?>');">
                                <input type="hidden" name="escalated_escalation_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $rule->id ); ?>">
                                <?php wp_nonce_field( 'escalated_escalation_delete_' . $rule->id, '_escalated_nonce' ); ?>
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
    <div class="escalated-card" style="padding: 15px; max-width: 800px;">
        <h2 style="margin-top: 0; font-size: 15px;">
            <?php echo $edit_item ? esc_html__( 'Edit Escalation Rule', 'escalated' ) : esc_html__( 'Add New Escalation Rule', 'escalated' ); ?>
        </h2>

        <form method="post">
            <?php if ( $edit_item ) : ?>
                <input type="hidden" name="escalated_escalation_action" value="update">
                <input type="hidden" name="id" value="<?php echo esc_attr( $edit_item->id ); ?>">
                <?php wp_nonce_field( 'escalated_escalation_update_' . $edit_item->id, '_escalated_nonce' ); ?>
            <?php else : ?>
                <input type="hidden" name="escalated_escalation_action" value="create">
                <?php wp_nonce_field( 'escalated_escalation_create', '_escalated_nonce' ); ?>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="rule-name"><?php esc_html_e( 'Name', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="rule-name" name="name" class="regular-text" required
                               value="<?php echo esc_attr( $edit_item->name ?? '' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rule-description"><?php esc_html_e( 'Description', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <textarea id="rule-description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $edit_item->description ?? '' ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rule-trigger-type"><?php esc_html_e( 'Trigger Type', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <select id="rule-trigger-type" name="trigger_type" class="regular-text">
                            <?php foreach ( $trigger_types as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $edit_item->trigger_type ?? '', $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rule-conditions"><?php esc_html_e( 'Conditions (JSON)', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <textarea id="rule-conditions" name="conditions" rows="6" class="large-text code"
                                  placeholder='[{"field": "status", "operator": "equals", "value": "open"}, {"field": "hours_since_created", "operator": "greater_than", "value": 24}]'><?php echo esc_textarea( $edit_item ? wp_json_encode( $edit_conditions, JSON_PRETTY_PRINT ) : '' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Enter conditions as a JSON array. Each condition should have "field", "operator", and "value" keys.', 'escalated' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rule-actions"><?php esc_html_e( 'Actions (JSON)', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <textarea id="rule-actions" name="actions_json" rows="6" class="large-text code"
                                  placeholder='[{"type": "change_priority", "value": "urgent"}, {"type": "assign_to", "value": 1}]'><?php echo esc_textarea( $edit_item ? wp_json_encode( $edit_actions, JSON_PRETTY_PRINT ) : '' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Enter actions as a JSON array. Each action should have "type" and "value" keys.', 'escalated' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rule-order"><?php esc_html_e( 'Sort Order', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="rule-order" name="sort_order" class="small-text" min="0" step="1"
                               value="<?php echo esc_attr( $edit_item->sort_order ?? 0 ); ?>">
                        <p class="description"><?php esc_html_e( 'Lower numbers execute first.', 'escalated' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Active', 'escalated' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" value="1"
                                <?php checked( $edit_item ? $edit_item->is_active : 1, 1 ); ?>>
                            <?php esc_html_e( 'Rule is active', 'escalated' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p>
                <?php
                submit_button(
                    $edit_item ? __( 'Update Rule', 'escalated' ) : __( 'Add Rule', 'escalated' ),
                    'primary',
                    'submit',
                    false
                );
                ?>
                <?php if ( $edit_item ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=escalated-escalation-rules' ) ); ?>" class="button" style="margin-left: 5px;">
                        <?php esc_html_e( 'Cancel', 'escalated' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </form>
    </div>
</div>
