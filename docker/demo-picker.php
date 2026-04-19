<?php

/**
 * Plugin Name: Escalated Demo Picker
 * Description: Adds /demo click-to-login picker for the Docker dev environment.
 */
if (! defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    add_rewrite_rule('^demo/?$', 'index.php?escalated_demo=picker', 'top');
    add_rewrite_rule('^demo/login/(\d+)/?$', 'index.php?escalated_demo=login&user_id=$matches[1]', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'escalated_demo';
    $vars[] = 'user_id';

    return $vars;
});

add_action('template_redirect', function () {
    $action = get_query_var('escalated_demo');
    if (! $action) {
        return;
    }
    if (getenv('APP_ENV') !== 'demo' && (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE !== 'development')) {
        status_header(404);
        exit;
    }

    if ($action === 'picker') {
        $users = get_users(['orderby' => 'ID', 'order' => 'ASC']);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Escalated · WP Demo</title>';
        echo '<style>*{box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:2rem}.wrap{max-width:720px;margin:0 auto}h1{font-size:1.5rem;margin:0 0 .25rem}p.lede{color:#94a3b8;margin:0 0 2rem}form{display:block;margin:0}button.user{display:flex;width:100%;align-items:center;justify-content:space-between;padding:.75rem 1rem;background:#1e293b;border:1px solid #334155;border-radius:8px;color:#f1f5f9;font-size:.95rem;cursor:pointer;margin-bottom:.5rem;text-align:left}button.user:hover{background:#273549;border-color:#475569}.meta{color:#94a3b8;font-size:.8rem}.badge{font-size:.7rem;padding:.15rem .5rem;border-radius:999px;background:#334155;color:#cbd5e1;margin-left:.5rem}.badge.admin{background:#7c3aed;color:#fff}.badge.agent{background:#0ea5e9;color:#fff}</style></head><body><div class="wrap"><h1>Escalated WordPress Demo</h1><p class="lede">Click a user to log in. Every restart resets the WordPress install.</p>';
        foreach ($users as $u) {
            $is_admin = user_can($u, 'manage_options');
            $is_agent = user_can($u, 'edit_posts') && ! $is_admin;
            $badge = $is_admin ? '<span class="badge admin">Admin</span>' : ($is_agent ? '<span class="badge agent">Agent</span>' : '');
            echo '<form method="POST" action="/demo/login/'.esc_attr($u->ID).'">';
            echo wp_nonce_field('demo_login_'.$u->ID, '_demo_nonce', true, false);
            echo '<button type="submit" class="user"><span>'.esc_html($u->display_name ?: $u->user_login).' '.$badge.'</span><span class="meta">'.esc_html($u->user_email).'</span></button></form>';
        }
        echo '</div></body></html>';
        exit;
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user_id = (int) get_query_var('user_id');
        if (! wp_verify_nonce($_POST['_demo_nonce'] ?? '', 'demo_login_'.$user_id)) {
            status_header(419);
            exit('CSRF check failed');
        }
        wp_set_auth_cookie($user_id, true);
        wp_set_current_user($user_id);
        wp_safe_redirect(user_can($user_id, 'edit_posts') ? admin_url() : home_url());
        exit;
    }
});

register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
