# Embedding the widget on other sites

The support widget, ticket form and live chat can run on a site other than
your WordPress site: a store, a marketing site or a customer portal loads one
script from WordPress. Nothing changes until you list the sites that may do
this.

## 1. Turn the widget on and allow the embedding sites

Under **Escalated > Settings > Widget**, check **Enable the support widget**
and list the sites in **Embedding Sites**, one per line (commas work too):

```text
https://shop.example.com
https://*.example.org
```

You can also list them in `wp-config.php`:

```php
define('ESCALATED_WIDGET_ALLOWED_ORIGINS', 'https://shop.example.com,https://*.example.org');
```

or from code with the `escalated_widget_allowed_origins` filter:

```php
add_filter('escalated_widget_allowed_origins', function (array $origins) {
    $origins[] = 'https://shop.example.com';

    return $origins;
});
```

All of them are combined. Each entry is an origin: `scheme://host[:port]`,
http or https, no path, query or user name. `https://*.example.org` matches
every subdomain of `example.org` at any depth, but not `example.org` itself and
not `http://`. A bare `*` is never accepted. The settings page refuses to save
the list when any entry is invalid and keeps the previous list; invalid
entries in the constant or the filter are ignored.

## 2. Add the script to the other site

```html
<script src="https://help.example.com/wp-json/escalated/v1/widget/embed.js" async></script>
```

For an on-page support form instead of a floating button:

```html
<div id="support-form"></div>
<script
  src="https://help.example.com/wp-json/escalated/v1/widget/embed.js"
  data-mode="inline"
  data-target="#support-form"
  data-tabs="ticket"
  data-meta-order-number="A-1001"
></script>
```

The script finds the API from its own URL, so this works with pretty
permalinks. With plain permalinks (`?rest_route=`), or when you serve the
bundle from a CDN, tell it where WordPress is:

```html
<script
  src="https://cdn.example.com/escalated-widget.js"
  data-base-url="https://help.example.com"
  data-widget-path="/wp-json/escalated/v1/widget"
  async
></script>
```

All host parameters (tabs, prefill, metadata, title, the JavaScript API) are
described in the frontend package's
[embedding guide](https://github.com/escalated-dev/escalated/blob/main/docs/embedding-the-widget.md).

## What the plugin does

`Escalated\Api\Widget_Cors` runs on every `/wp-json/escalated/v1/widget/*`
route, including the live chat routes under `/widget/chat/*`. For a request
whose `Origin` names another site on the list it:

- answers preflight requests (`OPTIONS` with `Access-Control-Request-Method`)
  with 204, `Access-Control-Allow-Origin: <origin>`, methods
  `GET, POST, OPTIONS`, headers
  `Accept, Authorization, Content-Type, X-Requested-With` and a 600 second
  `Access-Control-Max-Age`;
- adds `Access-Control-Allow-Origin`, `Access-Control-Expose-Headers:
  Retry-After` and `Vary: Origin` to every other response, including 400,
  403, 404 and 429 answers;
- runs the request as a guest (user 0) even if the same browser is signed in
  to WordPress. Guest tokens work as before.

WordPress core normally answers every REST request that has an `Origin` with
that origin and `Access-Control-Allow-Credentials: true`. The plugin turns
that off for the widget routes only, so `Access-Control-Allow-Credentials` is
never sent and a site that is not listed gets no `Access-Control-Allow-Origin`
at all. Preflights from such sites get a 204 without a grant. Same-origin
requests and requests without an `Origin` header are handled as before. The
widget routes need no REST nonce, so there is no CSRF check to skip. The
widget rate limit, the guest ticket rate limit and the guest policy apply to
embedded requests unchanged.

The plugin compares `Origin` with the requested scheme and `Host` and with
the home and site URLs. Behind a proxy that ends TLS, make sure WordPress
sees HTTPS (`is_ssl()`), as you would for anything else. Do not list your own
site: it does not need CORS.

### `GET /wp-json/escalated/v1/widget/config`

```json
{
  "enabled": true,
  "color": "#3B82F6",
  "position": "bottom-right",
  "greeting": "Hi there! How can we help?",
  "departments": [{ "id": 1, "name": "Sales" }],
  "kb_enabled": true,
  "guest_tickets_enabled": true,
  "accepts_metadata": true,
  "guest_verification_required": true,
  "status_lookup_enabled": true
}
```

`color`, `position` and `greeting` come from the widget settings.
`departments` lists the active departments. `kb_enabled` is true when the
knowledge base is on and public (or the visitor is signed in on a same-origin
page). `guest_verification_required` and `status_lookup_enabled` are true:
visitors confirm their email with a code before they open a ticket, start a
chat or look a ticket up (see [guest-access.md](guest-access.md)). While the widget is disabled the route answers 403 with
`{"enabled": false, "code": "escalated_widget_disabled", "message": "..."}`.

### The routes the widget calls

Paths are relative to `/wp-json/escalated/v1/widget`. Older clients keep
working: existing routes, parameters and fields are unchanged.

| Request | Answer |
|---|---|
| `GET /chat/availability` | `{ available, queue_depth }` |
| `GET /articles?q=` (2 to 200 characters) | `{ articles: [{ id, title, slug, excerpt, category }] }`. Without `q` (or with the older `search` parameter) the answer is the bare array, as before. |
| `GET /articles/{slug}` | `{ id, title, slug, body, content, category, related, feedback_enabled, date }`; `body` and `content` are the same HTML. |
| `POST /verification` `{ email, purpose }` | 202 `{ verification_id, expires_in: 600, message }`; the 8-digit code is emailed. `purpose` is `ticket`, `chat` or `lookup`. |
| `POST /tickets` `{ name, email, subject, description, department_id?, metadata?, verification_id, verification_code }` | 201 `{ message, reference, guest_access_token, expires_at }`, plus the legacy `guest_token` while legacy guest links are on. An unknown or inactive `department_id` is ignored. |
| `POST /lookup` `{ email, reference, verification_id, verification_code }` | `{ data: [{ reference, subject, guest_access_token, expires_at }] }` |
| `GET /tickets/{reference}` with `Authorization: Bearer <guest_access_token>` | `{ reference, subject, status, created_at, updated_at }` |
| `POST /chat/start` `{ name, email, department_id?, message?, metadata?, verification_id, verification_code }` | 201 `{ id, expires_at, session_id, status, messages, agent, guest_token, ... }` |
| `GET /chat/{id}/messages` | `{ messages: [{ id, body, is_agent, author: { name }, created_at }], agent, typing, ended, status }` |
| `POST /chat/{id}/messages` `{ body }` | 201; 422 once the chat has ended |
| `POST /chat/{id}/typing` | 200; fires the `escalated_chat_visitor_typing` action |
| `POST /chat/{id}/end` | 200; 422 if it already ended |
| `POST /chat/{id}/rate` `{ rating: 1-5, comment? }` | 200; 422 before the chat ends or once rated. Saved as the chat ticket's satisfaction rating. |

The chat `id` is a random 64-character chat token (also returned as
`guest_token`), not the numeric session id (returned as `session_id`). Only its
SHA-256 is stored, on the chat session, and it expires the guest access
lifetime after the chat starts (`expires_at`; setting
`guest_access_ttl_minutes`, default 1440). The `/chat/{id}/...` routes resolve
the chat from it and answer 404 for an unknown or expired token. They allow 120
requests a minute per IP, so the widget's 3-second polling fits; the other
widget routes keep the 30-a-minute limit. The older numeric routes
(`/chat/{session_id}/messages`, `/end` and `/poll` with the chat token as
`guest_token`) still work, and answer 403 once it has expired. `typing` is always `null` for now: WordPress has no
agent typing signal.

`GET /tickets/{reference}` needs a grant for that ticket; an email address
never opens it. While legacy guest links are on, the old `guest_token`
parameter still works too.

### Host parameters

`POST /widget/tickets` and `POST /widget/chat/start` accept an optional
`metadata` object. For a ticket it is stored in the ticket's `metadata`
column; for a chat, in the new `metadata` column of the chat session (shown as
the ticket's `chat_metadata`). The values come from the visitor's browser, so
only a flat map is kept: up to 25 entries, keys of letters, digits, `_ . : -`
(64 characters at most), and string, number or boolean values (strings
trimmed and cut to 500 characters). `page_url` is kept only when it is an
http(s) URL of at most 2048 characters. `embedded_on` is always set by the
server from the request's `Origin` and cannot be supplied by the page. A
`metadata` that is not an object, or has more than 50 keys, is rejected with
400. Treat all of it as unverified visitor input.

The chat session column is added by the plugin's normal schema upgrade the
first time the updated plugin loads.

### The script

`GET /wp-json/escalated/v1/widget/embed.js` serves
`assets/widget/escalated-widget.js`, the standalone bundle from
`@escalated-dev/escalated` 0.12.1, with
`Content-Type: application/javascript; charset=utf-8`,
`Cache-Control: public, max-age=3600`, an `ETag` (answering `If-None-Match`
with 304), `X-Content-Type-Options: nosniff`,
`Cross-Origin-Resource-Policy: cross-origin` and
`Access-Control-Allow-Origin: *`. It is served even while the widget is
disabled; the widget then shows nothing because `/config` answers 403.

To update the bundle, copy `dist/widget/escalated-widget.js` from the
published npm package (`npm pack @escalated-dev/escalated@<version>`) over the
vendored file.

## Troubleshooting

- **The browser reports a CORS error.** The page's origin is not on the list,
  or it differs in scheme or port (`http://` vs `https://`, `:8080`).
- **Another plugin or the web server adds CORS headers.** A security or CORS
  plugin, or a server rule, that sets `Access-Control-Allow-Origin` for all of
  `/wp-json/` overrides this. Leave `/wp-json/escalated/v1/widget` out of it.
- **A page cache serves the wrong headers.** Cache plugins that cache REST
  responses must vary on `Origin`, or skip `/wp-json/escalated/v1/widget`.
- **A signed-in agent testing the embed sees guest behaviour.** That is
  intended: requests from another site never act as the signed-in user.
