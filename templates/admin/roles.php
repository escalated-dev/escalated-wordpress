<?php
/**
 * Admin template: Roles (only while roles & permissions is on).
 *
 * The WordPress-native counterpart of the shared Escalated/Admin/Roles/Index
 * and Form pages: the WordPress roles that hold Escalated capabilities, and
 * the capability matrix for one of them.
 *
 * @var array $roles Rows from RoleCatalog::roles().
 * @var array $groups Permission definitions by group.
 * @var array|null $role The role being edited, if any.
 * @var bool $creating Whether the create form is shown.
 * @var bool $can_manage Whether the user may change roles.
 * @var string $message Flash message key.
 * @var string $error Flash error key.
 */
if (! defined('ABSPATH')) {
    exit;
}
$form_role = $role;
$show_form = $can_manage && ($creating || $form_role !== null);
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Roles', 'escalated'); ?></h1>
    <?php if ($can_manage && ! $show_form) { ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=escalated-roles&action=create')); ?>" class="page-title-action"><?php esc_html_e('New Role', 'escalated'); ?></a>
    <?php } ?>
    <hr class="wp-header-end">

    <?php if ($message) { ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php
            $messages = ['created' => __('Role created.', 'escalated'), 'updated' => __('Role updated.', 'escalated'), 'deleted' => __('Role deleted.', 'escalated')];
        echo esc_html($messages[$message] ?? __('Saved.', 'escalated'));
        ?>
        </p></div>
    <?php } ?>
    <?php if ($error) { ?>
        <div class="notice notice-error is-dismissible"><p>
            <?php
        $errors = [
            'system_role' => __('System roles cannot be deleted.', 'escalated'),
            'name' => __('The name field is required.', 'escalated'),
            'permissions' => __('The selected permissions are invalid.', 'escalated'),
        ];
        echo esc_html($errors[$error] ?? __('Could not save the role.', 'escalated'));
        ?>
        </p></div>
    <?php } ?>

    <?php if ($show_form) { ?>
        <form method="post" class="escalated-role-form">
            <input type="hidden" name="escalated_role_action" value="<?php echo $form_role ? 'update' : 'create'; ?>">
            <?php if ($form_role) { ?>
                <input type="hidden" name="role" value="<?php echo esc_attr($form_role['slug']); ?>">
            <?php } ?>
            <?php wp_nonce_field('escalated_role_'.($form_role ? 'update' : 'create'), '_escalated_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="escalated-role-name"><?php esc_html_e('Name', 'escalated'); ?></label></th>
                    <td><input type="text" id="escalated-role-name" name="name" class="regular-text" required value="<?php echo esc_attr($form_role['name'] ?? ''); ?>"></td>
                </tr>
            </table>
            <h2><?php esc_html_e('Permissions', 'escalated'); ?></h2>
            <?php foreach ($groups as $group => $definitions) { ?>
                <fieldset style="margin: 0 0 12px;">
                    <legend><strong><?php echo esc_html($group); ?></strong></legend>
                    <?php foreach ($definitions as $def) { ?>
                        <label style="display: inline-block; min-width: 220px;">
                            <input type="checkbox" name="permissions[]" value="<?php echo esc_attr($def['slug']); ?>"<?php checked(in_array($def['slug'], $form_role['permissions'] ?? [], true)); ?>>
                            <?php echo esc_html($def['name']); ?>
                        </label>
                    <?php } ?>
                </fieldset>
            <?php } ?>
            <?php submit_button($form_role ? __('Update', 'escalated') : __('Create', 'escalated')); ?>
        </form>
    <?php } else { ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Name', 'escalated'); ?></th>
                    <th scope="col"><?php esc_html_e('Permissions', 'escalated'); ?></th>
                    <th scope="col" style="width: 90px;"><?php esc_html_e('Users', 'escalated'); ?></th>
                    <th scope="col" style="width: 180px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $row) { ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($row['name']); ?></strong>
                            <?php if ($row['is_system']) { ?><span class="description">— <?php esc_html_e('system', 'escalated'); ?></span><?php } ?>
                            <br><code><?php echo esc_html($row['slug']); ?></code>
                        </td>
                        <td><?php echo esc_html((string) count($row['permissions'])); ?></td>
                        <td><?php echo esc_html((string) $row['users_count']); ?></td>
                        <td>
                            <?php if ($can_manage) { ?>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=escalated-roles&role='.$row['slug'])); ?>"><?php esc_html_e('Edit', 'escalated'); ?></a>
                                <?php if (! $row['is_system']) { ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="escalated_role_action" value="delete">
                                        <input type="hidden" name="role" value="<?php echo esc_attr($row['slug']); ?>">
                                        <?php wp_nonce_field('escalated_role_delete', '_escalated_nonce'); ?>
                                        <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'escalated'); ?></button>
                                    </form>
                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
