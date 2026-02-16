<?php
namespace Escalated\Admin;

use Escalated\Models\Tag;

class Admin_Tags {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    /**
     * Render the tags admin page.
     */
    public function render(): void {
        $tags      = Tag::all();
        $edit_item = null;

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && ! empty( $_GET['id'] ) ) {
            $edit_item = Tag::find( absint( $_GET['id'] ) );
        }

        $message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

        include ESCALATED_PLUGIN_DIR . 'templates/admin/tags.php';
    }

    /**
     * Handle POST actions: create, update, delete.
     */
    public function handle_actions(): void {
        if ( ! isset( $_POST['escalated_tag_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'escalated_manage_tags' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'escalated' ) );
        }

        $action   = sanitize_text_field( wp_unslash( $_POST['escalated_tag_action'] ) );
        $redirect = admin_url( 'admin.php?page=escalated-tags' );

        switch ( $action ) {
            case 'create':
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_tag_create' ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $data = [
                    'name'  => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'slug'  => sanitize_title( wp_unslash( $_POST['slug'] ?? $_POST['name'] ?? '' ) ),
                    'color' => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '#6B7280' ) ),
                ];

                $result = Tag::create( $data );
                if ( $result ) {
                    $redirect = add_query_arg( 'message', 'created', $redirect );
                } else {
                    $redirect = add_query_arg( 'message', 'error', $redirect );
                }
                break;

            case 'update':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_tag_update_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                $data = [
                    'name'  => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
                    'slug'  => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
                    'color' => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '#6B7280' ) ),
                ];

                Tag::update( $id, $data );
                $redirect = add_query_arg( 'message', 'updated', $redirect );
                break;

            case 'delete':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_escalated_nonce'] ?? '' ) ), 'escalated_tag_delete_' . $id ) ) {
                    wp_die( esc_html__( 'Security check failed.', 'escalated' ) );
                }

                Tag::delete( $id );
                $redirect = add_query_arg( 'message', 'deleted', $redirect );
                break;
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
