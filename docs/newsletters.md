# Newsletters

## Newsletters (optional, partial port)

Schema, models, and renderer for the admin-only newsletter broadcast feature. Off by default — flip `escalated_newsletters_enabled` option (or pass `1` through the standard Escalated settings UI) to turn it on. WP-special: uses WP options, WP-Cron, and WP capabilities (see CLAUDE.md / spec).

```php
// Plug in a Markdown renderer (Parsedown, league/commonmark, etc.)
add_filter('escalated_newsletter_markdown_renderer', function ($_, $md) {
    return Parsedown::instance()->text($md);
}, 10, 2);
```

Custom themes go in `templates/newsletter_themes/<slug>.php` and receive `$subject`, `$body` (pre-rendered safe HTML), `$unsubscribe_url`, `$view_in_browser_url`, `$brand` (associative array).

Ships:
- 5 new tables created by `Escalated\Activator::create_newsletter_tables()` (auto-called by the activator)
- `marketing_opt_out_at` column added to `escalated_contacts`
- 5 model wrappers under `includes/Models/Newsletter/`
- `includes/Services/Newsletter/NewsletterRenderer.php` — full renderer
- Two starter themes in `templates/newsletter_themes/{default,branded}.php`

Follow-up PR: WP-Cron tick for dispatcher, planner/tracker services, admin pages (custom or via the Inertia frontend), REST API endpoints for tracking + unsubscribe + view-in-browser, ESP webhook endpoints.
