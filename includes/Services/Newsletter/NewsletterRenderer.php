<?php

namespace Escalated\Services\Newsletter;

/**
 * Renders a newsletter delivery row to themed HTML. Mirrors the cross-backend
 * pattern: Markdown -> theme wrap -> click rewrite -> pixel injection.
 *
 * Markdown is host-pluggable via the `escalated_newsletter_markdown_renderer`
 * WordPress filter. The default fallback is a minimal escape+paragraph wrap.
 */
class NewsletterRenderer
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function render(object $delivery, object $newsletter, object $contact, ?object $template = null): string
    {
        $body_md = $newsletter->body_markdown ?? ($template->body_markdown ?? '');
        $theme_slug = $newsletter->theme ?? ($template->theme ?? get_option('escalated_newsletter_default_theme', 'default'));

        $body = $this->markdown_to_html($body_md);
        $body = $this->resolve_merge_fields($body, $contact, $delivery);

        $themed = $this->render_theme($theme_slug, [
            'subject' => $newsletter->subject ?? '',
            'body' => $body,
            'unsubscribe_url' => $this->unsubscribe_url($delivery),
            'view_in_browser_url' => $this->view_in_browser_url($delivery),
            'brand' => $this->brand(),
        ]);

        if (! get_option('escalated_newsletter_tracking_enabled', '1')) {
            return $themed;
        }

        return $this->inject_pixel($this->rewrite_links($themed, $delivery), $delivery);
    }

    public function unsubscribe_url(object $delivery): string
    {
        return untrailingslashit(home_url()) . '/escalated/n/u/' . $delivery->tracking_token;
    }

    public function view_in_browser_url(object $delivery): string
    {
        return untrailingslashit(home_url()) . '/escalated/n/v/' . $delivery->tracking_token;
    }

    private function brand(): array
    {
        return [
            'name' => get_option('escalated_brand_name', get_bloginfo('name')),
            'accent' => get_option('escalated_brand_accent', '#2563eb'),
            'logo_url' => get_option('escalated_brand_logo_url', ''),
            'physical_address' => get_option('escalated_brand_physical_address', ''),
        ];
    }

    private function markdown_to_html(string $md): string
    {
        $rendered = apply_filters('escalated_newsletter_markdown_renderer', null, $md);
        if (is_string($rendered)) {
            return $rendered;
        }
        $escaped = esc_html($md);
        return '<p>' . implode('</p><p>', preg_split('/\n{2,}/', $escaped)) . '</p>';
    }

    private function resolve_merge_fields(string $html, object $contact, object $delivery): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($contact, $delivery) {
            return esc_html($this->resolve_path(trim($m[1]), $contact, $delivery));
        }, $html);
    }

    private function resolve_path(string $path, object $contact, object $delivery): string
    {
        $name = (string) ($contact->name ?? '');
        switch ($path) {
            case 'contact.name': return $name;
            case 'contact.first_name': return explode(' ', $name)[0] ?? '';
            case 'contact.email': return (string) ($contact->email ?? '');
            case 'unsubscribe_url': return $this->unsubscribe_url($delivery);
            case 'view_in_browser_url': return $this->view_in_browser_url($delivery);
        }
        if (strpos($path, 'contact.metadata.') === 0) {
            $key = substr($path, strlen('contact.metadata.'));
            $meta = json_decode($contact->metadata ?? '{}', true) ?: [];
            return isset($meta[$key]) ? (string) $meta[$key] : '';
        }
        return '';
    }

    private function render_theme(string $slug, array $ctx): string
    {
        $themes_dir = apply_filters(
            'escalated_newsletter_themes_dir',
            ESCALATED_PLUGIN_DIR . 'templates/newsletter_themes'
        );
        $path = $themes_dir . '/' . $slug . '.php';
        if (! file_exists($path)) {
            $path = $themes_dir . '/default.php';
        }
        extract($ctx, EXTR_OVERWRITE);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    private function rewrite_links(string $html, object $delivery): string
    {
        $unsub = $this->unsubscribe_url($delivery);
        $view = $this->view_in_browser_url($delivery);
        return preg_replace_callback('#(<a\s[^>]*\bhref=)("|\')(.*?)\2#i', function ($m) use ($delivery, $unsub, $view) {
            $prefix = $m[1]; $quote = $m[2]; $href = $m[3];
            if ($href === '' || strpos($href, '#') === 0) return $m[0];
            $scheme = strtolower(parse_url($href, PHP_URL_SCHEME) ?: '');
            if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) return "{$prefix}{$quote}#{$quote}";
            if (in_array($scheme, ['mailto', 'tel'], true)) return $m[0];
            if (strpos($href, $unsub) === 0 || strpos($href, $view) === 0) return $m[0];
            $encoded = rtrim(strtr(base64_encode($href), '+/', '-_'), '=');
            $tracked = untrailingslashit(home_url()) . '/escalated/n/c/' . $delivery->tracking_token . '?u=' . $encoded;
            return "{$prefix}{$quote}{$tracked}{$quote}";
        }, $html);
    }

    private function inject_pixel(string $html, object $delivery): string
    {
        $url = untrailingslashit(home_url()) . '/escalated/n/o/' . $delivery->tracking_token . '.gif';
        $pixel = '<img src="' . esc_attr($url) . '" width="1" height="1" alt="" />';
        if (strpos($html, '</body>') !== false) {
            return str_replace('</body>', $pixel . '</body>', $html);
        }
        return $html . $pixel;
    }
}
