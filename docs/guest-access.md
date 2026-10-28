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

## Legacy links

Links sent before this release carry the ticket's permanent `guest_token`. They keep working on the guest view, guest replies and the widget status route while **Legacy guest links** (`guest_legacy_tokens_enabled`) is on, which is the default for the transition. Turn it off once guests have moved to expiring links. With it off, the widget also stops returning `guest_token`.

## Differences from Laravel

- Legacy permanent links are not cut off at once. A setting controls them.
- Live chat uses a random chat token rather than a sealed grant. Like Laravel's chat grant it expires `guest_access_ttl_minutes` after the chat starts, only its SHA-256 is stored, and it cannot open ticket routes; it is not rotated by a ticket link.
- Using up the code and creating the ticket are not one database transaction. A failed create hands the code back instead.
- There is no mobile guest API and no `external_reference` on tickets, so lookup matches the ticket reference only.
