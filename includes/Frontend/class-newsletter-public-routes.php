<?php

namespace Escalated\Frontend;

use Escalated\Models\Contact;
use Escalated\Models\Newsletter\Newsletter;
use Escalated\Models\Newsletter\NewsletterDelivery;
use Escalated\Models\Newsletter\NewsletterTemplate;
use Escalated\Services\Newsletter\BounceSuppressionStore;
use Escalated\Services\Newsletter\NewsletterConfig;
use Escalated\Services\Newsletter\NewsletterRenderer;
use Escalated\Services\Newsletter\NewsletterTracker;

/**
 * Pretty permalinks for /escalated/n/* tracking and unsubscribe endpoints.
 */
class Newsletter_Public_Routes
{
    private const PIXEL_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xfc\xff\xff?\x03\x00\x05\xfe\x02\xfe\xdc\xccY\xe7\x00\x00\x00\x00IEND\xaeB`\x82";

    public function register(): void
    {
        add_action('init', [$this, 'add_rewrites']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'handle']);
    }

    public function add_rewrites(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }
        add_rewrite_rule('^escalated/n/o/([A-Za-z0-9._-]+)/?$', 'index.php?escalated_n_action=open&escalated_n_token=$matches[1]', 'top');
        add_rewrite_rule('^escalated/n/c/([A-Za-z0-9_-]+)/?$', 'index.php?escalated_n_action=click&escalated_n_token=$matches[1]', 'top');
        add_rewrite_rule('^escalated/n/u/([A-Za-z0-9_-]+)/?$', 'index.php?escalated_n_action=unsubscribe&escalated_n_token=$matches[1]', 'top');
        add_rewrite_rule('^escalated/n/v/([A-Za-z0-9_-]+)/?$', 'index.php?escalated_n_action=view&escalated_n_token=$matches[1]', 'top');
    }

    /**
     * @param  array<string>  $vars
     * @return array<string>
     */
    public function query_vars(array $vars): array
    {
        $vars[] = 'escalated_n_action';
        $vars[] = 'escalated_n_token';

        return $vars;
    }

    public function handle(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }
        $action = get_query_var('escalated_n_action');
        $token = get_query_var('escalated_n_token');
        if (! $action || ! $token) {
            return;
        }

        match ($action) {
            'open' => $this->handle_open((string) $token),
            'click' => $this->handle_click((string) $token),
            'unsubscribe' => $this->handle_unsubscribe((string) $token),
            'view' => $this->handle_view((string) $token),
            default => null,
        };
    }

    private function handle_open(string $token): void
    {
        $clean = preg_replace('/\.(gif|png|jpg)$/i', '', $token) ?: $token;
        (new NewsletterTracker(new BounceSuppressionStore))->record_open($clean);
        status_header(200);
        header('Content-Type: image/png');
        header('Cache-Control: private, no-store, max-age=0');
        echo self::PIXEL_BYTES;
        exit;
    }

    private function handle_click(string $token): void
    {
        $encoded = isset($_GET['u']) ? (string) wp_unslash($_GET['u']) : '';
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            status_header(400);
            exit('Bad request');
        }
        $scheme = strtolower((string) wp_parse_url($decoded, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            status_header(400);
            exit('Bad request');
        }
        (new NewsletterTracker(new BounceSuppressionStore))->record_click($token, $decoded);
        wp_redirect($decoded, 302);
        exit;
    }

    private function handle_unsubscribe(string $token): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_unsubscribe_post($token);

            return;
        }
        $delivery = NewsletterDelivery::find_by_token($token);
        $this->render_unsubscribe($token, $delivery?->email_at_send ?? null, false);
    }

    private function handle_unsubscribe_post(string $token): void
    {
        $key = 'escalated_unsub_'.md5($_SERVER['REMOTE_ADDR'] ?? '');
        $count = (int) get_transient($key);
        if ($count >= 60) {
            status_header(429);
            exit('Too Many Requests');
        }
        set_transient($key, $count + 1, 60);

        $delivery = NewsletterDelivery::find_by_token($token);
        if ($delivery) {
            $contact = Contact::find((int) $delivery->contact_id);
            if ($contact) {
                global $wpdb;
                $wpdb->update(Contact::table(), [
                    'marketing_opt_out_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ], ['id' => (int) $contact->id]);
            }
        }
        $this->render_unsubscribe($token, $delivery?->email_at_send ?? null, true);
    }

    private function handle_view(string $token): void
    {
        $delivery = NewsletterDelivery::find_by_token($token);
        if (! $delivery) {
            status_header(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Email unavailable</title></head><body><p>This email is no longer available.</p></body></html>';
            exit;
        }
        $newsletter = Newsletter::find((int) $delivery->newsletter_id);
        $contact = Contact::find((int) $delivery->contact_id);
        if (! $newsletter || ! $contact) {
            status_header(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Email unavailable</title></head><body><p>This email is no longer available.</p></body></html>';
            exit;
        }
        $template = ! empty($newsletter->template_id)
            ? NewsletterTemplate::find((int) $newsletter->template_id)
            : null;
        $html = (new NewsletterRenderer)->render($delivery, $newsletter, $contact, $template);
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private function render_unsubscribe(string $token, ?string $email, bool $confirmed): void
    {
        status_header(200);
        $email = $email ? esc_html($email) : '';
        $token = esc_attr($token);
        $action = esc_url(home_url('/escalated/n/u/'.$token));
        include ESCALATED_PLUGIN_DIR.'templates/newsletters/unsubscribe.php';
        exit;
    }
}
