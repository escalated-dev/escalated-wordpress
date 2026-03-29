<?php
namespace Escalated\Admin;

use Escalated\Models\Automation;

class Admin_Automations {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    /**
     * Render the automations admin page.
     */
    public function render(): void {
        $automations = Automation::all();
        $edit_item   = null;

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && ! empty( $_GET['id'] ) ) {
            $edit_item = Automation::find( absint( $_GET['id'] ) );
        }

        $message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

        include ESCALATED_PLUGIN_DIR . 'templates/admin/automations.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void {
        if ( ! isset( $_POST['escalated_automation_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'escalated_automation_manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
        }

        $action   = sanitize_text_field( wp_unslash( $_POST['escalated_automation_action'] ) );
        $redirect = admin_url( 'admin.php?page=escalated-automations' );

        switch ( $action ) {
            case 'create':
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_automation_create' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $conditions = $this->parse_json_field( $_POST['conditions'] ?? '[]' );
                $actions    = $this->parse_json_field( $_POST['actions_json'] ?? '[]' );

                $data = [
                    'name'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'conditions' => $conditions,
                    'actions'    => $actions,
                    'position'   => absint( $_POST['position'] ?? 0 ),
                    'active'     => isset( $_POST['active'] ) ? 1 : 0,
                ];

                $result = Automation::create( $data );
                if ( $result ) {
                    $redirect = add_query_arg( 'message', 'created', $redirect );
                } else {
                    $redirect = add_query_arg( 'message', 'error', $redirect );
                }
                break;

            case 'update':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_automation_update_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $conditions = $this->parse_json_field( $_POST['conditions'] ?? '[]' );
                $actions    = $this->parse_json_field( $_POST['actions_json'] ?? '[]' );

                $data = [
                    'name'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'conditions' => $conditions,
                    'actions'    => $actions,
                    'position'   => absint( $_POST['position'] ?? 0 ),
                    'active'     => isset( $_POST['active'] ) ? 1 : 0,
                ];

                Automation::update( $id, $data );
                $redirect = add_query_arg( 'message', 'updated', $redirect );
                break;

            case 'delete':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_automation_delete_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                Automation::delete( $id );
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
            return array_map( 'sanitize_text_field', $value );
        }

        $decoded = json_decode( wp_unslash( $value ), true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
