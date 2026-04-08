<?php

/**
 * Branded Email Template - renders HTML email templates with configurable
 * branding (logo, accent color, footer text).
 */

namespace Escalated\Mail;

use Escalated\Models\Setting;

class Branded_Email_Template
{
    /**
     * Render a branded HTML email.
     *
     * @param  string  $subject  The email subject.
     * @param  string  $body  The email body content (HTML).
     * @param  array  $options  Optional overrides for branding settings.
     * @return string The complete branded HTML email.
     */
    public static function render(string $subject, string $body, array $options = []): string
    {
        $logo_url = $options['logo_url'] ?? Setting::get('email_logo_url', '');
        $accent_color = $options['accent_color'] ?? Setting::get('email_accent_color', '#3B82F6');
        $footer_text = $options['footer_text'] ?? Setting::get('email_footer_text', '');
        $company_name = $options['company_name'] ?? Setting::get('email_company_name', get_bloginfo('name'));

        // Sanitize the accent color.
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $accent_color)) {
            $accent_color = '#3B82F6';
        }

        $logo_html = '';
        if (! empty($logo_url)) {
            $logo_html = sprintf(
                '<img src="%s" alt="%s" style="max-height:50px;max-width:200px;display:block;margin:0 auto 16px auto;" />',
                esc_url($logo_url),
                esc_attr($company_name)
            );
        }

        $footer_html = '';
        if (! empty($footer_text)) {
            $footer_html = sprintf(
                '<p style="margin:0;font-size:12px;color:#9CA3AF;">%s</p>',
                esc_html($footer_text)
            );
        }

        return sprintf(
            '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>%1$s</title>
</head>
<body style="margin:0;padding:0;background-color:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background-color:#F3F4F6;">
<tr>
<td align="center" style="padding:24px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%%;">
<!-- Header -->
<tr>
<td style="background-color:%2$s;padding:24px;text-align:center;border-radius:8px 8px 0 0;">
%3$s
<h1 style="margin:0;font-size:18px;font-weight:600;color:#FFFFFF;">%4$s</h1>
</td>
</tr>
<!-- Body -->
<tr>
<td style="background-color:#FFFFFF;padding:24px;border-left:1px solid #E5E7EB;border-right:1px solid #E5E7EB;">
%5$s
</td>
</tr>
<!-- Footer -->
<tr>
<td style="background-color:#F9FAFB;padding:16px 24px;text-align:center;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 8px 8px;">
%6$s
<p style="margin:4px 0 0;font-size:11px;color:#D1D5DB;">Powered by Escalated</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>',
            esc_html($subject),
            esc_attr($accent_color),
            $logo_html,
            esc_html($company_name),
            $body,
            $footer_html
        );
    }

    /**
     * Get the current branding settings.
     *
     * @return array Associative array of branding settings.
     */
    public static function get_settings(): array
    {
        return [
            'logo_url' => Setting::get('email_logo_url', ''),
            'accent_color' => Setting::get('email_accent_color', '#3B82F6'),
            'footer_text' => Setting::get('email_footer_text', ''),
            'company_name' => Setting::get('email_company_name', get_bloginfo('name')),
        ];
    }

    /**
     * Update branding settings.
     *
     * @param  array  $settings  Settings to update.
     */
    public static function update_settings(array $settings): void
    {
        $allowed = ['email_logo_url', 'email_accent_color', 'email_footer_text', 'email_company_name'];

        foreach ($allowed as $key) {
            if (isset($settings[$key])) {
                Setting::set($key, sanitize_text_field($settings[$key]));
            }
        }
    }
}
