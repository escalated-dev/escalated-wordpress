# Embedding the admin in your own admin panel (optional)

Escalated's admin screens are wp-admin pages (`/wp-admin/admin.php?page=escalated…`,
the **Escalated** menu). That is the default and needs no setup. On
WordPress, wp-admin usually *is* your admin panel, so the admin is already
inside it. This page is for sites that go further: a custom wp-admin page or
theme template that lists Escalated's menu, or a separate admin front end
(headless, another origin, a non-WordPress back office) that shows Escalated's
screens in a frame. Nothing changes unless you turn this on.

This follows the cross-backend design (escalated-developer-context ADR
`2026-11-05-embeddable-admin`) as far as WordPress allows; the
[Configuration reference](#configuration-reference) says which parts do not
apply here and why.

## Pick your setup

| Your admin | Use |
|---|---|
| wp-admin itself | Nothing to do: the default |
| Vue + Inertia in this site | Not applicable (section 1); use the navigation data or the iframe |
| A wp-admin page or theme template of your own that should list Escalated's menu | Navigation helper (section 2) |
| Anything else: a headless or separate admin, another origin | iframe embed (section 3) |

## Configuration reference

Read from code only, never from the settings table. Constants in
`wp-config.php` win over environment variables of the same name; the
`escalated_ui_embed` filter runs last.

| Key | Constant / env var | Default | Meaning |
|---|---|---|---|
| `enabled` | `ESCALATED_EMBED` | `false` | Master switch. While `false` nothing below has any effect. |
| `mode` | `ESCALATED_EMBED_MODE` | `'layout'` | `'layout'`: Escalated's screens stay in wp-admin and are marked as embedded. `'iframe'`: screens requested with `escalated_embed=1` may be framed (section 3). Anything else counts as `'layout'`. |
| `frame_ancestors` | `ESCALATED_EMBED_FRAME_ANCESTORS` (comma-separated) | `[]` | iframe mode: origins allowed to frame the screens. Exact `http(s)://host[:port]` origins; `*`, wildcard hosts and paths are dropped. |
| `guard` | — | `null` | Your own check on every embedded Escalated screen, on top of the screen's capability: a capability name (`'manage_options'`) or a callable receiving the `WP_User` and returning a bool. Refused requests get a 403; the navigation is empty for that user. |
| `layout` | — | `null` | A label echoed as `escalatedAdmin.embed.layout`, for front ends that pick a layout by name. |

```php
// wp-config.php
define('ESCALATED_EMBED', true);
define('ESCALATED_EMBED_MODE', 'iframe');
define('ESCALATED_EMBED_FRAME_ANCESTORS', 'https://admin.example.com');
```

or from code: a plugin on `plugins_loaded`, or a theme's `functions.php`
(anything that runs before wp-admin builds its menu):

```php
add_action('plugins_loaded', function () {
    escalated_embed_admin([
        'mode' => 'iframe',                               // or 'layout'
        'frame_ancestors' => ['https://admin.example.com'],
        'guard' => 'manage_options',                      // optional
    ]);
});

// The same thing with the filter:
add_filter('escalated_ui_embed', function (array $config) {
    return ['enabled' => true, 'mode' => 'iframe', 'frame_ancestors' => ['https://admin.example.com']] + $config;
});
```

Keys of the cross-backend design that do not apply on WordPress:

| Key | Why not |
|---|---|
| `prefix` / `ESCALATED_EMBED_PREFIX` | The screens are wp-admin pages, addressed by `admin.php?page=…`. WordPress has no route table to remount them under another path, and outside wp-admin the screens' save handlers (`admin_init`), nonces and redirects do not run. |
| `middleware` | WordPress has no middleware stack. `guard` is the equivalent: your check runs first (on `admin_init` at priority 0) on every embedded screen. |
| `replace_admin_gate` | The admin gate here is WordPress's own capability check on each page (`escalated_ticket_view`, `escalated_settings_view`, …), done by wp-admin before the plugin is involved. It is never removed. To let other people in, grant those capabilities to their role. |
| `root_view` | wp-admin draws its chrome around every `admin.php` page and Escalated's screens are not Inertia pages, so there is no root view to swap. To list the menu in your own chrome, use section 2; for your own chrome around the screens, use the iframe. |

## 1. Vue + Inertia host

Not applicable. This plugin's screens are server-rendered wp-admin pages, not
Inertia pages, so `app.use(EscalatedPlugin, { layout, layoutScope: ['admin'] })`
from `@escalated-dev/escalated` has no Escalated admin pages to render inside
your layout. A Vue admin that is not part of wp-admin uses the iframe embed
(section 3); its sidebar can use the navigation data from section 2 (for
example by exposing `escalated_admin_navigation()` through your own REST
route for the signed-in user).

On Escalated's own screens the data is also in JavaScript, while embedding is
on, as `window.escalatedAdmin.embed` and `window.escalatedAdmin.navigation`
(the ADR's `escalated.embed` and `escalated.navigation`):

```jsonc
"embed": {
  "enabled": true,              // true on an embedded Escalated screen (layout mode: every Escalated screen; iframe mode: with escalated_embed=1)
  "mode": "iframe",
  "layout": null,
  "route": "escalated.admin.tags.index", // the ADR route name of this screen, or null
  "base_url": "/wp-admin/admin.php",
  "allowed_origins": ["https://admin.example.com"] // [] in layout mode
},
"navigation": [ /* NavigationItem[], see section 2 */ ]
```

Both keys are absent while embedding is off.

## 2. Server-rendered host: your own wp-admin page or theme template

`escalated_admin_navigation(?$user = null, ?$current_page = null)` returns
Escalated's admin menu for a user (default: the current one) as the ADR's
NavigationItem list. It works whether or not embedding is on.

```jsonc
{
  "key": "departments",
  "label": "Departments",
  "route": "escalated.admin.departments.index", // the ADR route name; WordPress itself addresses the screen by ?page=
  "url": "/wp-admin/admin.php?page=escalated-departments", // iframe mode adds &escalated_embed=1
  "icon": "departments",
  "position": 30,
  "active": true,         // the current request's ?page= is this screen
  "badge": null,
  "plugin": null,
  "children": []
}
```

Items, in order: Reports (10), Tickets (20), Departments (30), SLA Policies
(40), Escalation Rules (50), Workflows (53), Automations (53.5), Webhooks (54),
Tags (60), Canned Responses (70), Macros (80), Newsletters (81), Skills (82),
Users (83), Roles (84, only while roles & permissions is on), Business Hours
(86), API Tokens (90), Settings (100). Screens the design lists that this
plugin does not have (knowledge base, custom fields, statuses, capacity, audit
log, custom objects, plugins, import) are not listed. Each item is shown only
to a user holding the capability its wp-admin page requires, so the list never
links to a page that would refuse them: someone without
`escalated_settings_view` does not get Settings, and someone who may open no
Escalated screen gets `[]`. With a `guard`, a user it refuses gets `[]` too.

Add your own items (an add-on's screens) with the
`escalated_admin_navigation_items` filter; `capability` hides an item from
users without it, and items are sorted with the rest by `position`:

```php
add_filter('escalated_admin_navigation_items', function (array $items, WP_User $user) {
    $items[] = [
        'plugin' => 'billing',          // key becomes plugin:billing:0
        'label' => 'Billing',
        'url' => admin_url('admin.php?page=billing'),
        'icon' => 'card',
        'position' => 55,
        'badge' => 3,
        'capability' => 'manage_options',
        'children' => [['label' => 'Invoices', 'url' => admin_url('admin.php?page=billing-invoices')]],
    ];

    return $items;
}, 10, 2);
```

A template that renders the menu in your own chrome (this exact file is
`tests/Fixtures/embed/host-support-menu.php`, which the test suite renders):

```php
<?php
$items = function_exists('escalated_admin_navigation') ? escalated_admin_navigation() : [];
?>
<?php if ($items) { ?>
<nav class="host-support-menu" aria-label="<?php esc_attr_e('Support', 'escalated'); ?>">
    <ul>
        <?php foreach ($items as $item) { ?>
            <li>
                <a href="<?php echo esc_url($item['url']); ?>"<?php echo $item['active'] ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html($item['label']); ?>
                    <?php if ($item['badge'] !== null) { ?>
                        <span class="badge"><?php echo esc_html((string) $item['badge']); ?></span>
                    <?php } ?>
                </a>
            </li>
        <?php } ?>
    </ul>
</nav>
<?php } ?>
```

The links open Escalated's screens in wp-admin. Rendering a screen itself
inside a theme template or another wp-admin page is not supported: its save
handlers run on `admin_init` of its own `admin.php?page=` request and redirect
back there.

## 3. iframe

For an admin front end that is not wp-admin. Turn on iframe mode and list the
origins that may frame Escalated:

```php
define('ESCALATED_EMBED', true);
define('ESCALATED_EMBED_MODE', 'iframe');
define('ESCALATED_EMBED_FRAME_ANCESTORS', 'https://admin.example.com');
```

Frame a screen with `escalated_embed=1`:

```html
<iframe id="support-frame"
        src="https://support.example.com/wp-admin/admin.php?page=escalated&escalated_embed=1"
        title="Support" style="width:100%;border:0"></iframe>
```

A screen requested that way:

- is sent with `Content-Security-Policy: frame-ancestors 'self' https://admin.example.com`
  (`'self'` only when the list is empty) and without `X-Frame-Options`.
  WordPress sends `X-Frame-Options: SAMEORIGIN` and
  `frame-ancestors 'self'` on wp-admin (`send_frame_options_header`); that is
  skipped for this response only, and any `X-Frame-Options` another plugin
  adds later is removed just before the headers go out. A CSP another plugin
  sends is kept (if it has its own `frame-ancestors`, list the same origins
  there; browsers enforce both).
- renders without the wp-admin menu, admin bar and footer (body class
  `escalated-embedded`);
- keeps `escalated_embed=1` on its links, forms and redirects to other
  Escalated screens, so navigating stays framed;
- runs the frame bridge, which speaks the ADR's postMessage protocol (v1) to
  the parent, only when the parent's origin is on the list: `ready` on the
  first screen, `navigate` on each later one, `resize` whenever the height
  changes; it accepts `theme` and `navigate` (a path to an Escalated screen
  under `/wp-admin/admin.php`) from that parent only, and never posts to `*`.

The same screen without `escalated_embed=1`, every other wp-admin page, and
everything in layout mode keep WordPress's headers.

On the host page, the shared frontend's helper works unchanged:

```js
import { connectEscalatedFrame } from '@escalated-dev/escalated/embed';

const frame = connectEscalatedFrame(document.querySelector('#support-frame'), {
    origin: 'https://support.example.com',
    onNavigate: ({ url, title }) => highlightMenu(url),
});
frame.setTheme('dark');
frame.navigate('/wp-admin/admin.php?page=escalated-tags');
```

**Signing in.** The frame uses the WordPress login cookies. WordPress sets
them without a `SameSite` attribute, which browsers treat as `Lax`:

- A frame on the **same site** (for example `admin.example.com` framing
  `support.example.com`) gets the cookies; serve both over HTTPS.
- A frame on **another site** does not: the browser withholds `Lax` cookies
  there, so the frame shows wp-admin's sign-in redirect. WordPress has no
  setting for `SameSite=None; Secure` login cookies, and this plugin does not
  replace WordPress's pluggable cookie functions to force it. Browsers that
  block third-party cookies would refuse them anyway. Host the admin front end
  on the same site, or use section 2.
- `wp-login.php` itself always sends `X-Frame-Options`, so users sign in to
  WordPress in a normal tab, not inside the frame.

CSRF protection (WordPress nonces) is unchanged.

## Security

- Escalated's admin gate (WordPress's capability check on each screen),
  two-factor and roles & permissions all stay on; embedding never removes
  them. `guard` can only add a check.
- iframe framing is limited to the origins you list; nothing else can frame
  the screens, and only screens requested with `escalated_embed=1` can be
  framed at all.
- The frame bridge talks only to an allowed parent and accepts navigation
  only to Escalated screens on the same site.
- iframe mode registers a PHP `header_register_callback` for the framed
  response (to remove a late `X-Frame-Options`); PHP keeps one such callback,
  so on that response it replaces any other plugin's.

## What does not move

Everything: the wp-admin screens, their `?page=` slugs and capabilities, the
REST API (`/wp-json/escalated/v1/…`), the customer shortcodes, the guest
pages, the widget, single sign-on, two-factor and the newsletter pages keep
their URLs, with or without embedding.

## Turning it off

Remove the constants, environment variables, filter or
`escalated_embed_admin()` call. The screens are then served exactly as before,
with WordPress's own frame headers, and `escalatedAdmin` has no `embed` or
`navigation` keys.
