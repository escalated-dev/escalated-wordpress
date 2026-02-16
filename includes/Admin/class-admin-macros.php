<?php
namespace Escalated\Admin;

use Escalated\Models\Macro;

class Admin_Macros {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    /**
     * Render the macros admin page.
     */
    public function render(): void {
        $macros    = Macro::all();
        $edit_item = null;

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && ! empty( $_GET['id'] ) ) {
            $edit_item = Macro::find( absint( $_GET['id'] ) );
        }

        $message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

        include ESCALATED_PLUGIN_DIR . 'templates/admin/macros.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void {
        if ( ! isset( $_POST['escalated_macro_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'escalated_use_macros' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
        }

        $action   = sanitize_text_field( wp_unslash( $_POST['escalated_macro_action'] ) );
        $redirect = admin_url( 'admin.php?page=escalated-macros' );

        switch ( $action ) {
            case 'create':
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_macro_create' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $actions_data = $this->parse_json_field( $_POST['actions_json'] ?? '[]' );

                $data = [
                    'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                    'actions'     => $actions_data,
                    'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
                    'is_shared'   => isset( $_POST['is_shared'] ) ? 1 : 0,
                    'created_by'  => get_current_user_id(),
                ];

                $result = Macro::create( $data );
                if ( $result ) {
                    $redirect = add_query_arg( 'message', 'created', $redirect );
                } else {
                    $redirect = add_query_arg( 'message', 'error', $redirect );
                }
                break;

            case 'update':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_macro_update_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $actions_data = $this->parse_json_field( $_POST['actions_json'] ?? '[]' );

                $data = [
                    'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                    'actions'     => $actions_data,
                    'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
                    'is_shared'   => isset( $_POST['is_shared'] ) ? 1 : 0,
                ];

                Macro::update( $id, $data );
                $redirect = add_query_arg( 'message', 'updated', $redirect );
                break;

            case 'delete':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_macro_delete_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                Macro::delete( $id );
                $redirect = add_query_arg( 'message', 'deleted', $redirect );
                break;
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Parse a JSON string field from POST data.
     *
     * @param string|array $value Raw POST value.
     * @return array
     */
    private function parse_json_field( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        $decoded = json_decode( wp_unslash( $value ), true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
