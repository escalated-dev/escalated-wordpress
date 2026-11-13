# Customization

## Embedding the admin in your own admin panel (optional)

Escalated's admin is a set of wp-admin screens and needs no setup. If you run
your own admin on top of WordPress, you can list Escalated's menu there with
`escalated_admin_navigation()` (capability-filtered NavigationItem data), or
frame the screens chrome-free from another admin front end (iframe mode, only
for the origins you list). Off by default; nothing changes unless you set
`ESCALATED_EMBED` or call `escalated_embed_admin()`. See
[embedding the admin](embedding-admin.md).

## Frontend Shortcodes

Use these shortcodes on WordPress pages:

- `[escalated_tickets]` - Logged-in requester ticket list.
- `[escalated_create_ticket]` - Logged-in requester new ticket form.
- `[escalated_view_ticket]` - Ticket detail view:
  - Logged-in users: expects `?ticket=ESC-123`
  - Guests: expects `?guest_token=<link token>` (an expiring link from the guest form or a lookup; see [verified guest access](guest-access.md))
- `[escalated_guest_create]` - Guest ticket creation form (if enabled in settings).

### Guest rate limits

Guest ticket creation (widget `POST /widget/tickets` and the guest form) is limited to 5 per client IP per minute, and guest replies to 10. Over the limit the request gets `429` with `Retry-After`; a reply is counted before its guest token is checked.

- Settings: `guest_rate_limit_enabled` (default `1`), `guest_rate_limit_tickets_per_minute` (`5`), `guest_rate_limit_replies_per_minute` (`10`), or the `escalated_guest_rate_limit` filter.
- Counters are transients, so a persistent object cache (Redis, Memcached) shares them across servers.
- The client IP is `REMOTE_ADDR`. Behind a proxy or CDN, rewrite it from the trusted proxy's forwarded header, or every guest shares one IP.

### Embedding the widget on other sites

List the sites under **Settings > Widget > Embedding Sites** (or the `ESCALATED_WIDGET_ALLOWED_ORIGINS` constant), then add `<script src="https://your-site/wp-json/escalated/v1/widget/embed.js" async></script>` to the other site. Only listed origins get CORS headers, and their requests always run as a guest. See [embedding the widget](embedding-the-widget.md).

## Ticket subjects

A ticket has a **requester** (who raised it) and a **subject line** (free text).
Tickets can also be *about* host-app entities — a project, customer, or asset —
that are not people. Attach them as **subjects** so agents see context and can
link into your app.

WordPress does not own those models. Register allowed type strings and resolve
each `(type, id)` via a filter:

```php
// Allow attaching via the agent REST API (empty list disables API attach).
update_option('escalated_ticket_subject_types', ['project', 'customer']);

add_filter('escalated_resolve_ticket_subject', function ($subject, $type, $id) {
    if ($type === 'project') {
        $post = get_post((int) $id);
        if (! $post || $post->post_type !== 'project') {
            return null;
        }

        return new class ($post) implements \Escalated\Contracts\TicketSubject {
            public function __construct(private \WP_Post $post) {}

            public function ticketSubjectTitle(): string
            {
                return $this->post->post_title;
            }

            public function ticketSubjectSubtitle(): ?string
            {
                return 'Project';
            }

            public function ticketSubjectUrl(): ?string
            {
                return get_permalink($this->post);
            }

            public function ticketSubjectColor(): ?string
            {
                return null;
            }

            public function ticketSubjectIcon(): ?string
            {
                return 'folder';
            }
        };
    }

    return $subject;
}, 10, 3);
```

Programmatic attach/detach/sync (idempotent on `ticket_id` + `type` + `id`):

```php
use Escalated\Services\TicketSubjectService;

TicketSubjectService::attach($ticket_id, 'project', '42', 'project');
TicketSubjectService::detach($ticket_id, 'project', '42');
TicketSubjectService::sync($ticket_id, [
    ['type' => 'project', 'id' => '42', 'role' => 'primary'],
    ['type' => 'customer', 'id' => '7'],
]);
```

Serialized on each ticket as `subjects[]`:

`{ type, id, role, title, subtitle, url, color, icon, missing }`

(`title` falls back to `type#id` when the resolver returns null.)

Admin REST (logged-in user with `escalated_ticket_edit`):

- `POST /wp-json/escalated/v1/admin/tickets/{ref}/subjects` — body: `type`, `id`, optional `role`
- `DELETE /wp-json/escalated/v1/admin/tickets/{ref}/subjects/{link_id}`

## Custom Ticket Actions

Host plugins can add custom buttons to the agent ticket screen and handle clicks
with normal WordPress hooks. Register actions via the `escalated_ticket_actions`
filter:

```php
add_filter('escalated_ticket_actions', function (array $actions): array {
    $actions[] = [
        'key' => 'sync-crm',
        'label' => 'Sync CRM',
        'variant' => 'primary',           // primary | secondary | danger
        'confirmation' => 'Sync this ticket to the CRM?',
        'metadata' => ['icon' => 'refresh-cw'],
        // 'visible' / 'enabled' may be bool or callable($ticket, $user_id)
        'enabled' => fn ($ticket, $user_id) => empty($ticket->metadata['crm_synced']),
    ];

    return $actions;
});
```

Visible actions appear on the ticket detail response as `custom_actions` (each
with a `url` and `method`). Triggering one
(`POST /wp-json/escalated/v1/tickets/{ref}/actions/{key}`) validates the action
is visible (404) and enabled (403), then fires the
`escalated_ticket_action_triggered` hook:

```php
add_action('escalated_ticket_action_triggered', function ($ticket, $action_key, $user_id, $payload, $metadata) {
    if ($action_key !== 'sync-crm') {
        return;
    }
    // your handler
}, 10, 5);
```

Escalated also records an internal note on the ticket whenever an action fires,
for auditability.

## Translations

Escalated for WordPress consumes translations from the central
[`escalated-dev/locale`](https://github.com/escalated-dev/escalated-locale)
Composer package, which is the single source of truth for translations
across every Escalated host plugin.

At runtime the plugin loads translations in two layers (later layer wins):

1. **Central** — `vendor/escalated-dev/locale/languages/escalated-{locale}.mo`
   (installed automatically via `composer install`).
2. **Local overrides** — `languages/overrides/escalated-{locale}.mo`
   (drop your own compiled `.mo` here to override individual entries
   without forking the central package).

If the central package is not yet installed, the plugin falls back to
the legacy in-tree `languages/*.po`/`*.mo` files so existing sites keep
working.

To submit translation fixes, open a PR against
[`escalated-dev/escalated-locale`](https://github.com/escalated-dev/escalated-locale).
Do **not** edit the in-tree `.po` files — they exist only as a fallback
and will be removed once the central package reaches a stable release.
