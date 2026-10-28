# Verified guest access

Guests prove they own their mailbox before they can open a ticket, start a live chat, or look a ticket up. This follows escalated-laravel's `docs/guest-access.md` and what the shared widget (`@escalated-dev/escalated`) sends.

## Email codes

`POST /wp-json/escalated/v1/widget/verification` (widget) or `POST /wp-json/escalated/v1/guest/verification` (guest pages):

```json
{ "email": "recipient@example.com", "purpose": "ticket" }
```

- `purpose` is `ticket`, `chat` or `lookup`.
- The answer is 202 with `{ "verification_id", "expires_in": 600, "message" }`. Only the email contains the eight-digit code.
- Both routes answer 403 while guest tickets are off.

Send `verification_id` and `verification_code` with the operation. A code:

- expires after 10 minutes
- allows 5 guesses (wrong guesses stay counted)
- can be used once
- is bound to the normalised email and the purpose

If the operation then fails, the code is handed back so the guest can retry. Only keyed digests of the email and code are stored, in `escalated_guest_verifications`.

**Budgets.**
- Codes: 3 an hour for one mailbox from one client IP, and 10 an hour for one mailbox across all IPs. Over budget answers 429 with `Retry-After`. Tune them with the `guest_verification_codes_per_client_per_hour` and `guest_verification_codes_per_mailbox_per_hour` settings, or the `escalated_guest_verification_limits` filter.
- Requests: the per-IP guest reply limit also covers the verification and lookup routes.

## Where proof is needed

| Operation | Purpose |
|---|---|
| Widget `POST /widget/tickets` | `ticket` |
| `[escalated_guest_create]` form (AJAX `escalated_guest_create`) | `ticket` |
| Widget `POST /widget/chat/start` | `chat` |
| `POST /widget/lookup`, `POST /guest/lookup` | `lookup` |

The guest form asks for a code when it is first submitted, shows a code field, and creates the ticket on the second submit.

## Access grants

- **Creating a ticket.** The widget answers with `guest_access_token` and `expires_at`. The guest form answers with a private `view_url`.
- **The token.** It is an opaque grant: encrypted and authenticated with a key derived from the site's auth salt. Do not parse it. The ticket stores only a SHA-256 of a random nonce.
- **Lifetime.** Grants last for the **Guest link lifetime** setting (`guest_access_ttl_minutes`): 24 hours by default, clamped to 5 minutes through 7 days.
- **Ending a grant.**
  - Issuing a new grant (for example through lookup) rotates the old one.
  - Changing the ticket's guest email voids every grant, even through raw SQL, because the grant carries a digest of the email.
  - `Escalated\Services\GuestAccess::revoke($ticket)` ends them explicitly.

Grants are read by:

- `GET /widget/tickets/{reference}` with `Authorization: Bearer <grant>`. The grant must be for that reference; an email address never opens it.
- The guest ticket view (`[escalated_view_ticket]` with `?guest_token=<grant>`) and its reply form.

Guest responses send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

## Lookup

`POST /widget/lookup` or `POST /guest/lookup` takes `{ email, reference, verification_id, verification_code }` with a `lookup` code. It answers `{ "data": [{ "reference", "subject", "guest_access_token", "expires_at" }] }`. The guest route adds `view_url` to each entry.

- Only guest tickets with that reference and that guest email match, at most 20.
- The result may be empty; the code is still used up.

## Mobile guest API

The JSON routes a mobile app uses to let a guest file a ticket, read it, reply and get back in later. The canonical contract is [mobile-guest-api](https://github.com/escalated-dev/escalated-developer-context/blob/main/domain-model/mobile-guest-api.md); this section lists what is specific to WordPress.

| Method | Route | Guest budget |
|---|---|---|
| POST | `/wp-json/escalated/v1/mobile/guest/verification` | tickets (submissions) |
| POST | `/wp-json/escalated/v1/mobile/guest/lookup` | replies |
| POST | `/wp-json/escalated/v1/mobile/guest/tickets` | tickets (submissions) |
| GET | `/wp-json/escalated/v1/mobile/guest/tickets/{token}` | API limit only |
| POST | `/wp-json/escalated/v1/mobile/guest/tickets/{token}/replies` | replies |

**Where they live.** The contract puts the routes under the backend's REST API prefix plus `/mobile`. This plugin has one REST namespace, `escalated/v1`, which every other route (agent API, widget, guest pages) already uses, so the mobile prefix is `escalated/v1/mobile`. Sites without pretty permalinks reach the same routes through `?rest_route=/escalated/v1/mobile/guest/...`.

**Always registered.** WordPress has no switch that turns this plugin's REST API on or off, so the routes are registered with the others in `Api_Bootstrap`. They answer 403 while guest tickets are off (verification, lookup and create), and they do not depend on the widget being enabled.

**Handlers.** `verification` and `lookup` are the same handlers as `/widget/...` and `/guest/...` (lookup without the `view_url`). Create and reply use `TicketService::create_guest()` (contact, guest policy, default SLA), the same codes and the same grants:

- `{token}` is the grant. A grant from the mobile create works on `GET /widget/tickets/{reference}` and on the guest ticket page, and a grant from the widget, the guest form or a lookup works here.
- Legacy permanent `guest_token` links and live-chat tokens never open the mobile routes. Any bad, expired, rotated, revoked, malformed or over-long credential answers 404.
- Create checks, in order: guest tickets on (403), fields (422), the `ticket` code (422 on `verification_code`, nothing created). It stores the normalised guest email, channel `web`, and up to 10 attachments, and answers `201 { data, message }` with the grant in `data.guest_access_token`.
- Replies check the grant (404), a closed ticket (422), the fields (422), then that `email` matches the ticket's guest email ignoring case and surrounding spaces (403). The reply is public and authorless, and fires `escalated_reply_created`.

**Rate limits.**
- API layer: the `api_rate_limit` setting (60 a minute), counted per client IP because these routes carry no API token. Every answer has `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Over the limit: `429 { "message": "Too many requests.", "retry_after": n }` with `Retry-After`.
- Guest layer: the per-IP `GuestRateLimiter` budgets that the widget and the guest form already use, so one budget per IP covers all three surfaces. Its `tickets` budget is the contract's submissions bucket and its `replies` budget is the replies bucket. Over budget answers 429 with `Retry-After`.

Every answer, errors included, has `Cache-Control: private, no-store` and `Referrer-Policy: no-referrer`. Redact the `{token}` path segment from your web server's access logs.

**Attachments.** Each reply attachment's `url` is the normal download route with a signed `guest` parameter instead of a login. The signature covers the attachment, the ticket's current grant and guest email, and the grant's expiry. The link stops working when the grant expires, rotates or is revoked. It never serves internal-note files, and the grant itself is never put in the URL.

## Legacy links

Links sent before this release carry the ticket's permanent `guest_token`. They keep working on the guest view, guest replies and the widget status route while **Legacy guest links** (`guest_legacy_tokens_enabled`) is on, which is the default for the transition. Turn it off once guests have moved to expiring links. With it off, the widget also stops returning `guest_token`.

## Differences from Laravel

- Legacy permanent links are not cut off at once. A setting controls them.
- Live chat uses a random chat token rather than a sealed grant. Like Laravel's chat grant it expires `guest_access_ttl_minutes` after the chat starts, only its SHA-256 is stored, and it cannot open ticket routes; it is not rotated by a ticket link.
- Using up the code and creating the ticket are not one database transaction. A failed create hands the code back instead.
- Mobile guest API:
  - There is no separate requests budget. The per-IP API limit (`api_rate_limit`, 60 a minute) covers every mobile guest route.
  - Lookup and code requests on the widget and guest-page routes still count against the replies budget. On the mobile routes, code requests count against the tickets budget, as in the contract.
  - The guest replies budget defaults to 10 a minute (Laravel: 30).
  - `verification_id` is this plugin's 32-character id, not a UUID.
  - `escalated_ticket_created` fires inside `create_guest()`, before the create's attachments are stored, rather than after a commit.
- There is no `external_reference` on tickets, so lookup matches the ticket reference only.
