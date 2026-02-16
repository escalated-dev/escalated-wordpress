<?php
/**
 * Admin template: API Tokens
 *
 * @var array       $tokens      All API token objects.
 * @var string|null $plain_token The plain-text token shown once after creation.
 * @var string      $message     Flash message key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'API Tokens', 'escalated' ); ?></h1>
    <hr class="wp-header-end">

    <?php if ( $message ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $messages = [
                    'created' => __( 'API token created successfully.', 'escalated' ),
                    'deleted' => __( 'API token deleted successfully.', 'escalated' ),
                    'error'   => __( 'An error occurred. Please try again.', 'escalated' ),
                ];
                echo esc_html( $messages[ $message ] ?? __( 'Action completed.', 'escalated' ) );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( $plain_token ) : ?>
        <div class="notice notice-warning" style="border-left-color: #F59E0B;">
            <p>
                <strong><?php esc_html_e( 'Your new API token:', 'escalated' ); ?></strong>
            </p>
            <p>
                <code style="font-size: 14px; padding: 8px 12px; background: #f6f7f7; display: inline-block; word-break: break-all; user-select: all;"><?php echo esc_html( $plain_token ); ?></code>
            </p>
            <p style="color: #92400E;">
                <strong><?php esc_html_e( 'Make sure to copy this token now. You will not be able to see it again!', 'escalated' ); ?></strong>
            </p>
        </div>
    <?php endif; ?>

    <!-- Token List -->
    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e( 'Name', 'escalated' ); ?></th>
                <th scope="col" style="width: 200px;"><?php esc_html_e( 'Token', 'escalated' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'User', 'escalated' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'Abilities', 'escalated' ); ?></th>
                <th scope="col" style="width: 140px;"><?php esc_html_e( 'Last Used', 'escalated' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'Expires', 'escalated' ); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e( 'Created', 'escalated' ); ?></th>
                <th scope="col" style="width: 100px;"><?php esc_html_e( 'Actions', 'escalated' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $tokens ) ) : ?>
                <tr>
                    <td colspan="8"><?php esc_html_e( 'No API tokens found.', 'escalated' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $tokens as $token ) :
                    $user = get_userdata( $token->user_id );
                    $user_name = $user ? $user->display_name : __( 'Unknown', 'escalated' );

                    // Mask the stored hash.
                    $token_display = '****' . substr( $token->token_hash ?? $token->token ?? '', -8 );

                    // Parse abilities.
                    $abilities = json_decode( $token->abilities ?? '["*"]', true );
                    $abilities_str = is_array( $abilities ) ? implode( ', ', $abilities ) : '*';

                    // Expired check.
                    $is_expired = false;
                    if ( ! empty( $token->expires_at ) && strtotime( $token->expires_at ) < current_time( 'timestamp' ) ) {
                        $is_expired = true;
                    }
                ?>
                    <tr<?php echo $is_expired ? ' style="opacity: 0.6;"' : ''; ?>>
                        <td>
                            <strong><?php echo esc_html( $token->name ); ?></strong>
                            <?php if ( $is_expired ) : ?>
                                <span style="color: #EF4444; font-size: 11px; font-weight: 600;"><?php esc_html_e( '(Expired)', 'escalated' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="font-size: 12px;"><?php echo esc_html( $token_display ); ?></code>
                        </td>
                        <td><?php echo esc_html( $user_name ); ?></td>
                        <td>
                            <span style="font-size: 12px;"><?php echo esc_html( $abilities_str ); ?></span>
                        </td>
                        <td>
                            <?php if ( $token->last_used_at ) : ?>
                                <span title="<?php echo esc_attr( $token->last_used_at ); ?>">
                                    <?php echo esc_html( human_time_diff( strtotime( $token->last_used_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'escalated' ) ); ?>
                                </span>
                                <?php if ( $token->last_used_ip ) : ?>
                                    <br><small style="color: #999;"><?php echo esc_html( $token->last_used_ip ); ?></small>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color: #999;"><?php esc_html_e( 'Never', 'escalated' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $token->expires_at ) : ?>
                                <span title="<?php echo esc_attr( $token->expires_at ); ?>" style="<?php echo $is_expired ? 'color: #EF4444;' : ''; ?>">
                                    <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $token->expires_at ) ) ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #999;"><?php esc_html_e( 'Never', 'escalated' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $token->created_at ) ) ); ?>
                        </td>
                        <td>
                            <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to revoke this token?', 'escalated' ) ); ?>');">
                                <input type="hidden" name="escalated_token_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $token->id ); ?>">
                                <?php wp_nonce_field( 'escalated_token_delete_' . $token->id, '_escalated_nonce' ); ?>
                                <button type="submit" class="button button-small button-link-delete">
                                    <?php esc_html_e( 'Revoke', 'escalated' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Create Token Form -->
    <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px; max-width: 600px;">
        <h2 style="margin-top: 0; font-size: 15px;"><?php esc_html_e( 'Create New Token', 'escalated' ); ?></h2>

        <form method="post">
            <input type="hidden" name="escalated_token_action" value="create">
            <?php wp_nonce_field( 'escalated_token_create', '_escalated_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="token-name"><?php esc_html_e( 'Token Name', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="token-name" name="name" class="regular-text" required
                               placeholder="<?php esc_attr_e( 'e.g., My Integration', 'escalated' ); ?>">
                        <p class="description"><?php esc_html_e( 'A descriptive name to identify this token.', 'escalated' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Abilities', 'escalated' ); ?></th>
                    <td>
                        <fieldset>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox" name="abilities[]" value="*" checked>
                                <?php esc_html_e( 'Full access (all abilities)', 'escalated' ); ?>
                            </label>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox" name="abilities[]" value="tickets:read">
                                <?php esc_html_e( 'Read tickets', 'escalated' ); ?>
                            </label>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox" name="abilities[]" value="tickets:write">
                                <?php esc_html_e( 'Create/update tickets', 'escalated' ); ?>
                            </label>
                            <label style="display: block; margin-bottom: 6px;">
                                <input type="checkbox" name="abilities[]" value="replies:write">
                                <?php esc_html_e( 'Create replies', 'escalated' ); ?>
                            </label>
                            <label style="display: block;">
                                <input type="checkbox" name="abilities[]" value="reports:read">
                                <?php esc_html_e( 'Read reports', 'escalated' ); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="token-expires"><?php esc_html_e( 'Expiration Date', 'escalated' ); ?></label>
                    </th>
                    <td>
                        <input type="date" id="token-expires" name="expires_at" class="regular-text"
                               min="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>">
                        <p class="description"><?php esc_html_e( 'Leave blank for a token that never expires.', 'escalated' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Create Token', 'escalated' ) ); ?>
        </form>
    </div>
</div>
