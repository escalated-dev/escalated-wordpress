<?php
namespace Escalated\Admin;

use Escalated\Models\EscalationRule;

class Admin_Escalation_Rules {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    /**
     * Render the escalation rules admin page.
     */
    public function render(): void {
        $rules     = EscalationRule::all();
        $edit_item = null;

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && ! empty( $_GET['id'] ) ) {
            $edit_item = EscalationRule::find( absint( $_GET['id'] ) );
        }

        $message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

        include ESCALATED_PLUGIN_DIR . 'templates/admin/escalation-rules.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void {
        if ( ! isset( $_POST['escalated_escalation_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'escalated_manage_escalation_rules' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
        }

        $action   = sanitize_text_field( wp_unslash( $_POST['escalated_escalation_action'] ) );
        $redirect = admin_url( 'admin.php?page=escalated-escalation-rules' );

        switch ( $action ) {
            case 'create':
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_escalation_create' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $conditions = $this->parse_json_field( $_POST['conditions'] ?? '[]' );
                $actions    = $this->parse_json_field( $_POST['actions_json'] ?? '[]' );

                $data = [
                    'name'         => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'description'  => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                    'trigger_type' => sanitize_text_field( wp_unslash( $_POST['trigger_type'] ?? '' ) ),
                    'conditions'   => $conditions,
                    'actions'      => $actions,
                    'sort_order'   => absint( $_POST['sort_order'] ?? 0 ),
                    'is_active'    => isset( $_POST['is_active'] ) ? 1 : 0,
                ];

                $result = EscalationRule::create( $data );
                if ( $result ) {
                    $redirect = add_query_arg( 'message', 'created', $redirect );
                } else {
                    $redirect = add_query_arg( 'message', 'error', $redirect );
                }
                break;

            case 'update':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_escalation_update_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $conditions = $this->parse_json_field( $_POST['conditions'] ?? '[]' );
                $actions    = $this->parse_json_field( $_POST['actions_json'] ?? '[]' );

                $data = [
                    'name'         => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'description'  => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                    'trigger_type' => sanitize_text_field( wp_unslash( $_POST['trigger_type'] ?? '' ) ),
                    'conditions'   => $conditions,
                    'actions'      => $actions,
                    'sort_order'   => absint( $_POST['sort_order'] ?? 0 ),
                    'is_active'    => isset( $_POST['is_active'] ) ? 1 : 0,
                ];

                EscalationRule::update( $id, $data );
                $redirect = add_query_arg( 'message', 'updated', $redirect );
                break;

            case 'delete':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_escalation_delete_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                EscalationRule::delete( $id );
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
