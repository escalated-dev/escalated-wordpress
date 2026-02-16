<?php
/**
 * Admin template: Departments
 *
 * @var array       $departments  All department objects.
 * @var object|null $edit_item    Department being edited (or null).
 * @var array       $agent_counts Associative array of department_id => agent count.
 * @var string      $message      Flash message key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Departments', 'escalated' ); ?></h1>
    <hr class="wp-header-end">

    <?php if ( $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __( 'Department created successfully.', 'escalated' ),
                    'updated' => __( 'Department updated successfully.', 'escalated' ),
                    'deleted' => __( 'Department deleted successfully.', 'escalated' ),
                    'error'   => __( 'An error occurred. Please try again.', 'escalated' ),
                ];
                echo esc_html( $messages[ $message ] ?? __( 'Action completed.', 'escalated' ) );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <div style="display: flex; gap: 20px; align-items: flex-start;">

        <!-- Form -->
        <div style="width: 350px; flex-shrink: 0;">
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px;">
                <h2 style="margin-top: 0; font-size: 15px;">
                    <?php echo $edit_item ? esc_html__( 'Edit Department', 'escalated' ) : esc_html__( 'Add New Department', 'escalated' ); ?>
                </h2>

                <form method="post">
                    <?php if ( $edit_item ) : ?>
                        <input type="hidden" name="escalated_department_action" value="update">
                        <input type="hidden" name="id" value="<?php echo esc_attr( $edit_item->id ); ?>">
                        <?php wp_nonce_field( 'escalated_department_update_' . $edit_item->id, '_escalated_nonce' ); ?>
                    <?php else : ?>
                        <input type="hidden" name="escalated_department_action" value="create">
                        <?php wp_nonce_field( 'escalated_department_create', '_escalated_nonce' ); ?>
                    <?php endif; ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="dept-name"><?php esc_html_e( 'Name', 'escalated' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dept-name" name="name" class="regular-text" required
                                       value="<?php echo esc_attr( $edit_item->name ?? '' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dept-slug"><?php esc_html_e( 'Slug', 'escalated' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="dept-slug" name="slug" class="regular-text"
                                       value="<?php echo esc_attr( $edit_item->slug ?? '' ); ?>">
                                <p class="description"><?php esc_html_e( 'Leave blank to auto-generate from name.', 'escalated' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dept-description"><?php esc_html_e( 'Description', 'escalated' ); ?></label>
                            </th>
                            <td>
                                <textarea id="dept-description" name="description" rows="4" class="large-text"><?php echo esc_textarea( $edit_item->description ?? '' ); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Active', 'escalated' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" value="1"
                                        <?php checked( $edit_item ? $edit_item->is_active : 1, 1 ); ?>>
                                    <?php esc_html_e( 'Department is active', 'escalated' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php
                    submit_button(
                        $edit_item ? __( 'Update Department', 'escalated' ) : __( 'Add Department', 'escalated' )
                    );
                    ?>

                    <?php if ( $edit_item ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=escalated-departments' ) ); ?>" class="button">
                            <?php esc_html_e( 'Cancel', 'escalated' ); ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- List -->
        <div style="flex: 1; min-width: 0;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Name', 'escalated' ); ?></th>
                        <th scope="col" style="width: 120px;"><?php esc_html_e( 'Slug', 'escalated' ); ?></th>
                        <th scope="col" style="width: 80px;"><?php esc_html_e( 'Agents', 'escalated' ); ?></th>
                        <th scope="col" style="width: 80px;"><?php esc_html_e( 'Active', 'escalated' ); ?></th>
                        <th scope="col" style="width: 150px;"><?php esc_html_e( 'Actions', 'escalated' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $departments ) ) : ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e( 'No departments found.', 'escalated' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $departments as $dept ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $dept->name ); ?></strong>
                                    <?php if ( ! empty( $dept->description ) ) : ?>
                                        <p class="description" style="margin: 2px 0 0;"><?php echo esc_html( wp_trim_words( $dept->description, 10 ) ); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html( $dept->slug ); ?></code></td>
                                <td><?php echo esc_html( $agent_counts[ $dept->id ] ?? 0 ); ?></td>
                                <td>
                                    <?php if ( $dept->is_active ) : ?>
                                        <span style="color: #10B981; font-weight: 600;"><?php esc_html_e( 'Yes', 'escalated' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: #EF4444;"><?php esc_html_e( 'No', 'escalated' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=escalated-departments&action=edit&id=' . $dept->id ) ); ?>" class="button button-small">
                                        <?php esc_html_e( 'Edit', 'escalated' ); ?>
                                    </a>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this department?', 'escalated' ) ); ?>');">
                                        <input type="hidden" name="escalated_department_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo esc_attr( $dept->id ); ?>">
                                        <?php wp_nonce_field( 'escalated_department_delete_' . $dept->id, '_escalated_nonce' ); ?>
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
        </div>

    </div>
</div>
