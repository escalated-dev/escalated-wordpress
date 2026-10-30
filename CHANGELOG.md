# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Upgrading
- Two-factor authentication is a new install-level switch, off by default
  (`ESCALATED_TWO_FACTOR` constant or environment variable, or the
  `escalated_access_two_factor` filter). While it is off, the
  token-authenticated `admin/two-factor` REST routes answer 404. TOTP secrets
  generated from now on are 32 characters. The schema upgrade adds
  `two_factors.last_used_step`. See docs/access-control.md.
- Roles & permissions is a new install-level switch, off by default
  (`define('ESCALATED_ROLES_PERMISSIONS', true);` in wp-config.php, the
  environment variable of the same name, or the
  `escalated_access_roles_permissions` filter). Off, nothing changes. Before
  turning it on, give every staff user an Escalated role (`escalated_admin`,
  `escalated_agent`, `escalated_light_agent` or a role of yours holding
  `escalated_*` capabilities): with it on, staff API tokens and the ticket
  screen's actions need the owner's own capabilities. See
  docs/access-control.md.

### Added
- Two-factor authentication, enforced behind its switch (ADR
  2026-10-29-configurable-access-features, as escalated-laravel): TOTP
  through pragmarx/google2fa with one step of drift and no code reuse,
  Escalated > Two-Factor enrolment for every staff user, a challenge between
  the WordPress login and the Escalated screens (5 attempts a minute, kept in
  the WordPress login session, so every new login is challenged), single-use
  recovery codes, "require two-factor" (`ESCALATED_TWO_FACTOR_REQUIRED` or
  the `two_factor_required` setting) and `wp escalated two-factor-reset`.
  Staff REST requests made with the login cookie get `403
  two_factor_required` until the challenge is passed; API tokens are not
  challenged.
- Roles & permissions, enforced behind its switch (ADR
  2026-10-29-configurable-access-features, as escalated-laravel, with
  WordPress capabilities as the permissions and WordPress roles as the
  roles): every staff REST route needs its capability
  (`Escalated\Support\RoutePermissions`, fail closed), a status change to
  resolved/closed/reopened needs `ticket.close`, API tokens are checked as
  their owner, and the ticket screen's actions need their own capabilities.
  Escalated > Roles edits the Escalated capabilities of WordPress roles, and
  the Users screen assigns them, while the switch is on.

## [1.6.6] - 2026-10-28

### Upgrading
- `POST widget/chat/start` now answers exactly as escalated-laravel: `201
  { id, session_id, expires_at, ticket_reference, status, agent_name }`, where
  `id` and `session_id` are both the chat token (plus `guest_token`,
  `visitor_name`, `messages`, `agent` and `created_at`). The numeric
  `session_id` and `ticket_id` are no longer returned: use `id` with the
  `widget/chat/{id}/...` routes and `ticket_reference` to name the ticket. The
  older numeric `widget/chat/{n}/messages|end|poll` routes still work for
  clients that already hold a numeric id.
- The plugin's schema upgrade (run automatically on load, as for every
  release) adds `visitor_token_hash` and `visitor_token_expires_at` to the
  chat sessions table. Chats already in progress keep working: the next
  widget request with their token (still the chat ticket's `guest_token`)
  moves the token's hash to the session with a full lifetime and clears the
  ticket's copy.
- Widget chat tokens now expire `guest_access_ttl_minutes` after the chat
  starts (default 1440, clamped to 5 minutes .. 7 days; filter
  `escalated_guest_access_ttl_minutes`), as in escalated-laravel. After that
  every `/widget/chat/{id}/...` route answers 404, the older numeric routes
  answer 403, and the visitor starts a new chat.
- `ChatSessionService::start()` returns the token once as
  `$session->visitor_token`; new chat tickets no longer carry it as their
  `guest_token`.

### Added
- **Mobile guest API** under `/wp-json/escalated/v1/mobile/guest/`:
  `POST verification`, `POST lookup`, `POST tickets`,
  `GET tickets/{token}` and `POST tickets/{token}/replies`, following
  escalated-developer-context `domain-model/mobile-guest-api.md`. The routes
  use the existing email codes, guest access grants and per-IP guest budgets,
  so grants work across the widget, the guest pages and the mobile routes.
  Anything other than an active grant (including chat tokens and legacy
  links) answers 404, and every answer is `Cache-Control: private, no-store`.
  The routes are also limited per client IP by the `api_rate_limit` setting,
  with `X-RateLimit-*` headers. See `docs/guest-access.md`.
- Attachment downloads accept a signed guest link
  (`AttachmentService::guest_download_url()`), which the mobile ticket
  object uses for reply attachments. It works only while the ticket's grant
  is active and never for internal-note files.

### Security
- **Widget chat tokens are stored hashed and expire.** The token returned by
  `POST /widget/chat/start` (`id`, `guest_token`) was the chat ticket's plain
  `guest_token`, never expired, and (with legacy tokens on) also opened the
  ticket's guest routes. It is now kept only as a SHA-256 on the chat
  session, the start answer carries `expires_at`, and an unknown or expired
  token is refused on every chat route.

## [1.6.5] - 2026-10-22

### Added
- Live chat transfer (escalated-laravel `escalated.admin.chat.transfer`): `POST /wp-json/escalated/v1/chat/{session_id}/transfer`, with an API token like the other agent chat endpoints. The body is `{agent_id, department_id}` (either or both); the shared `ChatActionBar`'s `{type: "agent"|"department", id}` is accepted too. With an agent, the session and ticket are reassigned to them. With only a department, the ticket moves there and the chat goes back to the queue (unassigned, waiting), then chat routing runs again for the new department. A missing or non-integer target, an unknown department, an ended chat, or an agent who is not staff or cannot see the ticket is a 422; an unknown session is a 404. New `ChatSessionService::transfer()` and `escalated_chat_transferred` action (`$session, $from_agent_id, $to_agent_id, $to_department_id`).
- `chat.transferred` runs workflows (new trigger, "Chat transferred") and is sent to webhooks subscribed to it; the webhook form offers it. Its payload is the ticket plus `chat {session_id, ticket_id, status, agent_id, department_id, from_agent_id, to_agent_id, to_department_id}`, as escalated-laravel's. Existing webhooks are not subscribed to it automatically.
- Webhooks now receive the other live chat events, as escalated-laravel's `DispatchWebhook`: `chat.started` (a chat begins), `chat.assigned` (an agent accepts it), `chat.message` (an agent or visitor message) and `chat.ended`. Until now only workflows ran on them. The webhook form offers them. Each payload is the ticket plus `chat {session_id, ticket_id, status, agent_id, department_id}`; `chat.message` adds `reply_id` and `author_type` (`agent` or `visitor`) but never the body, and `chat.ended` adds `ended_by` (`agent`, `customer` or `system`). Existing webhooks, including one folded from the old single webhook URL, are not subscribed to them automatically; tick them on the webhook to receive them.
- `escalated_chat_message` passes a third argument, `$author_type` (`agent` or `visitor`), and `escalated_chat_ended` a third, `$ended_by`. `ChatSessionService::end()` takes an optional third `$ended_by` (default `system`); the agent and widget end endpoints pass `agent` and `customer`. Existing listeners registered for fewer arguments are unaffected.

## [1.6.4] - 2026-10-22

### Upgrading
- New tables `escalated_business_schedules` and `escalated_holidays`, and a nullable `business_schedule_id` column on `escalated_sla_policies` and `escalated_departments`, added by the normal schema upgrade.
- Nothing changes until a business schedule exists. With none, SLA policies limited to business hours keep the fixed hours of the `escalated_business_hours_start`, `escalated_business_hours_end` and `escalated_business_days` filters.
- Once a schedule is marked **Default**, every policy limited to business hours counts on it (or on a more specific one) instead of on those filters. Business-hours deadlines then follow the schedule's time zone rather than the site clock.
- The Settings screen shows a stored inbound mailbox password as `********`. Saving the form with that mask (or the older `abc*****` form) keeps the stored password; any other value replaces it, and an empty field clears it, as before.
- On the Webhooks screen, leaving the secret blank keeps the stored secret and ticking **Remove secret** (`clear_secret`) removes it, as before. `clear_secret` is now read as a boolean, so `0` or `false` no longer removes the secret.
- `GET /escalated/v1/admin/api-tokens` returns `token_masked` as `********` for every token. It used to carry the first 8 characters of the stored value.

### Added
- **Business hours and holidays**, like escalated-laravel. Escalated > Business Hours (wp-admin, `escalated_sla_manage` to change, `escalated_sla_view` to look) lists schedules and edits each one: name, time zone, default switch, opening and closing time per weekday, and holidays (one-off or every year).
  - SLA deadlines for a policy limited to business hours are counted on the policy's own schedule, else the ticket department's, else the default schedule. The SLA policy and department screens gain a **Business schedule** picker.
  - `Escalated\Services\BusinessHoursCalculator` counts real elapsed time in the schedule's time zone, so daylight-saving days are handled; it supports overnight hours (closing before opening) and `24:00`, and skips holidays. It gives the same deadlines as the other Escalated backends.
  - Deleting a schedule removes its holidays, and policies and departments that used it fall back to the default schedule.

### Security
- **Stored secrets are no longer sent to the browser.** The Settings screen put the inbound mailbox (IMAP) password into the page as the field's value; it now gets a full mask. The API tokens screen showed the last 8 and `GET /admin/api-tokens` the first 8 characters of each stored token, which for a token issued before tokens were hashed (and not used since) is part of the token itself; both now show a fixed mask. A `clear_secret` of `false` removed a webhook's signing secret. A new guard test covers the Webhooks list, edit and delivery-log screens, the Settings screen, the API tokens screen and REST list and create, and the two-factor status, confirm and recovery-code endpoints. Only the one-time plaintext token on creation and the 2FA setup response hand out a secret, by design.

## [1.6.3] - 2026-10-21

### Upgrading
- **Guest clients need the code step.** The bundled widget (0.12.3) already has it, and the `[escalated_guest_create]` form gains it in this release. Custom widget or API clients must call `/verification` and send `verification_id` and `verification_code` with tickets, chats and lookups.
- Guest links sent before this release (permanent `guest_token`) keep working while **Legacy guest links** is on under Settings (the default). Turn it off once guests have moved to expiring links. Live chat sessions still use the chat ticket's token as their id and are not affected.
- New table `escalated_guest_verifications` and ticket columns `guest_access_hash` and `guest_access_expires_at`, added by the normal schema upgrade.
- The Settings page no longer has Webhook URL and Secret fields. On upgrade an existing URL becomes a webhook subscribed to every event with the same secret, so receivers keep getting events. A URL set in the `webhook_url` setting from code is moved the same way when the next event fires.
- Webhooks are now sent a moment after the change, from WP-Cron, instead of during the request. Sites with WP-Cron disabled need a real cron calling `wp-cron.php`.
- `NotificationService::send_webhook()` now queues the event for every subscribed webhook and returns whether any delivery was queued.
- `escalated_webhook_sent` and `escalated_webhook_failed` receive the webhook row as an extra last argument.
- Webhook receivers get more events than before, including `note.created` for internal notes (id and author only). Ignore the names you do not handle.
- `reply.agent_reply` (staff replies only) is kept as a WordPress extension trigger.

### Security
- **Verified guest access**, like escalated-laravel. See [docs/guest-access.md](docs/guest-access.md).
  - Guests prove their mailbox with an emailed 8-digit code before they open a ticket (widget or `[escalated_guest_create]` form), start a chat, or look a ticket up. A code lasts 10 minutes, allows 5 guesses, works once, and is bound to the email and purpose. Only keyed digests are stored. Codes are limited to 3 an hour per mailbox per client IP and 10 an hour per mailbox.
  - New routes `POST /widget/verification`, `POST /widget/lookup`, `POST /guest/verification` and `POST /guest/lookup`.
  - Tickets get expiring, encrypted access grants (24 hours by default; the Guest link lifetime setting allows 5 minutes to 7 days). A new grant rotates the old one, and changing the guest email voids them. The widget status route takes the grant as `Authorization: Bearer`.
  - `/widget/config` now sends `guest_verification_required: true` and `status_lookup_enabled: true`, so the bundled widget shows its code step and Status tab.

### Added
- **Ticket merge**, like escalated-laravel. The ticket screen has a Merge box. `POST /tickets/{ref}/merge` with `{target_reference}` and `GET /tickets/merge-search?q=` do the same over the API. Replies move to the target, both tickets get a system note, and the source is closed with the new `merged_into_id` column, in one transaction. Needs the `escalated_ticket_merge` capability. See the README.
- **Webhook subscriptions** under Escalated > Webhooks, like escalated-laravel: any number of webhooks, each with its own URL, signing secret, events and active switch, and a delivery log with a Retry button. See [docs/webhooks.md](docs/webhooks.md).
  - Events are queued and sent from WP-Cron (Action Scheduler when installed), so a slow receiver no longer holds up ticket requests.
  - Failed deliveries are retried after 120 s and 240 s, then marked failed.
  - The URL must resolve only to public addresses. This is checked when a webhook is saved and again before every attempt; redirects are not followed and the connection is pinned to the checked address.
  - New tables `escalated_webhooks` and `escalated_webhook_deliveries`, created by the normal schema upgrade.
- **Workflow admin.** Escalated > Workflows creates, edits, enables, orders and deletes workflows, so the existing engine can be configured without code. REST routes under `/admin/workflows` (index, new, edit, create, update, toggle, reorder, delete) take the shared workflow admin contract's body and return 422 with `errors` on bad input. Both need `escalated_automation_manage` and are audited. See [docs/workflows.md](docs/workflows.md).
- Workflow conditions can use `tags`, `hours_since_created` and `hours_since_updated`, and `add_note` fills in `{{field}}` templates, as the contract says.

### Changed
- **One event vocabulary** (escalated-developer-context ADR `2026-10-21-event-vocabulary`). Webhooks and workflow triggers use the canonical names, the same as escalated-laravel. The list is in `Escalated\Services\EventVocabulary`.
  - The workflow tag trigger is `ticket.tag_added` (was `ticket.tagged`).
  - Workflows can now also run on `ticket.tag_removed`, `ticket.unassigned`, `ticket.escalated`, `ticket.resolved`, `ticket.closed`, `note.created` and the live chat events `chat.started`, `chat.assigned`, `chat.message` and `chat.ended`.
  - The webhook now also receives `ticket.resolved`, `ticket.closed`, `ticket.reopened`, `ticket.escalated`, `ticket.priority_changed`, `ticket.tag_added`, `ticket.tag_removed`, `sla.warning` (once per warning, not on every SLA cron run) and `note.created`. `note.created` carries the note's id and author, never its body.
- **Legacy names still work when read.** A workflow stored as `ticket.tagged`, `ticket.replied`, `internal_note.added`, `ticket.untagged` or another legacy name from the ADR runs on the canonical event. Workflow log rows record the canonical name.

### Fixed
- Customers and guests see the replies on their ticket pages again. The public reply query filtered on a column that does not exist, so it returned nothing.
- The `[escalated_guest_create]` form posts `guest_name` / `guest_email`, which the handler did not read, so it always failed with "All fields are required". Both spellings are read now.
- A workflow whose actions fire its own trigger (a `ticket.updated` workflow that changes the priority) no longer runs itself without end. It runs once per event; other events still chain.

## [1.6.2] - 2026-10-20

### Changed
- The bundled widget is now `@escalated-dev/escalated` 0.12.3. It keeps its script settings when `embed.js` is placed in the page `<head>`, and Enter no longer picks a stale mention suggestion.

### Security
- **Attachments are private.** Inbound email and form attachments were written to the public uploads directory under the sender's file name, with HTML and SVG allowed, and linked directly, so an emailed `invoice.html` or `logo.svg` could run script on the WordPress origin when an agent opened it. Bytes now go to `wp-content/uploads/escalated-private` (deny-all `.htaccess`/`web.config`; move it outside the web root with the `escalated_attachment_storage_dir` filter on nginx) under random extension-less names, the MIME type is detected from the bytes, and HTML, SVG, XHTML and more PHP extensions are blocked. Files are served only by `GET /escalated/v1/attachments/{id}/download`, which requires a bearer token (`tickets:read`) or a signed-in session with a REST nonce, ticket visibility for staff, or being the requester (internal-note attachments are staff only). Downloads always use `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and a sandbox CSP, and anything outside a short list of safe types is sent as `application/octet-stream`. Serialized attachment `url`s and the agent ticket page now point at this route. Files stored by earlier versions under `uploads/escalated` keep working through the route; their old public URLs are no longer linked, but delete or protect that directory to stop direct access.
- Widget ticket creation and chat start answer 403 when guest tickets are disabled.
- The widget knowledge base routes (`/widget/articles`, `/widget/articles/{slug}`, `/feedback`) answer 404 when the knowledge base is off and 403 to anonymous callers when it is not public.
- The legacy `POST /widget/chat/{session_id}/messages` route now requires the widget to be enabled and is rate limited like the other chat routes.
- Chat start counts against the per-IP guest ticket limit, and visitor chat messages against the guest reply limit.
- Widget rate-limit 429 responses carry `Retry-After`.

### Added
- Outbound webhooks include `occurred_at`, an ISO-8601 UTC time. `timestamp` is unchanged.

## [1.6.1] - 2026-10-19

### Changed
- The bundled widget is now `@escalated-dev/escalated` 0.12.2. It hides its Check Status tab when `/config` returns `status_lookup_enabled: false`.

### Added
- **Embed the widget on other sites.** List the sites under Settings > Widget > Embedding Sites, in the `ESCALATED_WIDGET_ALLOWED_ORIGINS` constant or with the `escalated_widget_allowed_origins` filter (`https://shop.example.com`, `https://*.example.com`), then load `/wp-json/escalated/v1/widget/embed.js` there. Listed origins get CORS headers on every `/widget` route, chat included, and always run as a guest; WordPress core's own CORS headers are turned off for those routes, so unlisted sites get no grant. Nothing changes until a site is listed. See [docs/embedding-the-widget.md](docs/embedding-the-widget.md).
- Widget settings (enable, color, position, greeting, embedding sites) on the Escalated settings page. Invalid embedding sites are refused and the previous list is kept.
- Widget tickets and chats accept an optional `metadata` object of host parameters (order number, plan, page URL, ...). It is sanitised to at most 25 flat scalar entries; `embedded_on` is set from the request's `Origin`. `/widget/config` returns `accepts_metadata: true`. Chat sessions get a nullable `metadata` column through the normal schema upgrade.
- The widget API matches the shared widget (`@escalated-dev/escalated` 0.12). `/widget/config` adds `enabled`, `departments`, `kb_enabled`, `guest_tickets_enabled` and `status_lookup_enabled: false`, and a disabled widget answers 403 with `enabled: false`. `/widget/articles?q=` answers `{articles: [...]}` (the `search` parameter keeps the bare array), and `/widget/articles/{slug}` adds `body`. Widget tickets accept `department_id` and return `guest_access_token`. Chat start returns the chat ticket's random guest token as `id` (the numeric id moves to `session_id`), and new `/widget/chat/{id}/messages` (GET and POST), `/typing`, `/end` and `/rate` routes are keyed by it. The older numeric chat routes are unchanged. See [docs/embedding-the-widget.md](docs/embedding-the-widget.md).
- Opt-in department visibility and account restrictions across ticket queries, child records, REST tokens, reports, widget lookups, assignment and notifications. Administrators retain access; existing installations default to unrestricted staff visibility.
- Nullable indexed ticket account identities with host requester/subject and agent resolver hooks. Escalation rules, workflows and automations support exact account and ticket-type conditions.
- Optional department/type SLA targets and settings controls. Department moves preserve SLA deadlines; explicit reselection uses the original creation time.

### Fixed
- Satisfaction ratings are saved. The model wrote an `updated_at` column the table does not have, so every insert failed, including the front-end rating form.
- Install ticket links before their first API read and run schema updates on the configured package database. Upgrades preserve existing rows and support custom prefixes.
- Invoke the assignment service correctly from the token API and report ineligible assignees as validation errors.

## [1.6.0] - 2026-10-08

This release changes how inbound email is matched to tickets. Read
**Upgrading** before deploying.

### Upgrading
- **Inbound replies are accepted only from the ticket's requester.** A matched
  email becomes a reply only when `From` is the ticket's guest email or the
  requester's email, and it is posted as the requester. Any other sender,
  including an agent answering a notification by email, gets a new ticket
  instead, and that email no longer reopens a resolved or closed ticket. Agents
  should reply in wp-admin.
- **With an inbound secret set** (`escalated_email_inbound_secret`), only the
  signed Reply-To address links mail to a ticket. Header and subject matching
  are used only when no secret is configured.
- **Guest tickets and replies are rate-limited per client IP** (5 tickets and
  10 replies per minute by default). Adjust or disable with the
  `guest_rate_limit_enabled`, `guest_rate_limit_tickets_per_minute` and
  `guest_rate_limit_replies_per_minute` settings or the
  `escalated_guest_rate_limit` filter. Sites behind a proxy must rewrite
  `REMOTE_ADDR` from the trusted proxy's forwarded header, or every guest shares
  one budget.

### Added
- **Per-IP rate limit on guest tickets and replies.** Guest ticket creation (widget REST endpoint and the guest form) is capped at 5 per client IP per minute and guest replies at 10, each with its own counter. Over the limit the request gets `429` with `Retry-After`. Replies are counted before the guest token is checked, so wrong-token guesses count too. Configure with the `guest_rate_limit_enabled`, `guest_rate_limit_tickets_per_minute` and `guest_rate_limit_replies_per_minute` settings or the `escalated_guest_rate_limit` filter. Sites behind a proxy must rewrite `REMOTE_ADDR` from the trusted proxy's forwarded header, or every guest shares one IP.

### Fixed
- **Inbound replies come only from the ticket's requester.** With an inbound secret set, only the signed Reply-To address links mail to a ticket. A matched email is a reply only when `From` is the ticket's guest email or requester email, and it is posted as the requester; any other sender gets a new ticket, and only an accepted reply reopens a resolved or closed ticket.

## [1.5.2] - 2026-09-13

### Fixed
- **Inbound email never created a ticket.** The inbound controller passed the parsed `Inbound_Message` to `InboundEmailService::process()`, which only accepted an array. The `TypeError` was caught, so every Mailgun, Postmark and SES webhook got a 500 and nothing was created. The service now takes `Inbound_Message`.
  - **Hooks:** `escalated_inbound_email_processed` and `escalated_inbound_email_failed` pass the `Inbound_Message` instead of an array.
  - **Response:** a handled email answers `{"status": "ok", "ticket_id": …}`.
- **Most replies could not find their ticket.** Replies now follow the documented resolution chain, first match wins:
  1. a `<ticket-{id}@domain>` Message-ID in `In-Reply-To`
  2. the same in `References`
  3. a signed `reply+{id}.{hmac8}@domain` recipient
  4. a `[ESC-00001]` subject reference
  5. an earlier inbound email's Message-ID

  Before, only the last two were checked, and the subject reference came first.
- **Inbound Message-IDs were logged empty.** `sanitize_text_field()` removes `<id@host>` as if it were an HTML tag. Replies could not be matched to an earlier inbound email, a redelivered email opened a second ticket, and later emails could not be logged at all, because the UNIQUE `message_id` index already held an empty value. Message-IDs are now stored as sent, less any whitespace, and a redelivered email is recognised before it is logged.
- **An SES subscription confirmation opened a ticket.** It is now confirmed and answered without being processed as email.
- **Inbound attachments were stored as `unnamed`, and Mailgun attachments were dropped.** The service read `filename`, `contentType` and `content`, but the adapters send `name`, `type` and, for Mailgun, an uploaded file. Every adapter now hands over the attachment bytes under the same keys.
- **SES inbound refused SNS topics that sign with SignatureVersion 2.** Every SNS signature was checked with SHA1, so a topic set to SignatureVersion 2 (SHA256) failed verification and its email got a 403. The algorithm now follows the message's `SignatureVersion`: SHA1 for `1`, SHA256 for `2`. A missing or unknown version is rejected before the signing certificate is fetched.
- **The workflow `delay` action waited seconds, not minutes.** The workflow admin contract defines the `delay` value as minutes, and the admin builder labels the field "Wait (minutes)", but the executor added it to the clock as seconds. A "Wait 15 minutes" step resumed after 15 seconds. The value is now read as minutes.
  - **Existing workflows:** a `delay` value that was entered as seconds now waits 60 times as long.
  - **Queued jobs:** runs already paused keep the resume time they were given.
- **Removing a tag ran `ticket.tagged` workflows.** `WorkflowListener` ran the `ticket.tagged` trigger on both `escalated_tag_added` and `escalated_tag_removed`. It now runs only when a tag is added.

## [1.5.1] - 2026-09-13

### Security
- **The SES inbound webhook could be made to fetch any URL.**
  - **The hole:** the route is public, and its signing-certificate check accepted any host ending in `.amazonaws.com`, including an attacker's S3 bucket. A forged `SubscriptionConfirmation` then made the site request an arbitrary `SubscribeURL`.
  - **The fix:**
    - The certificate URL and `SubscribeURL` must be https to an Amazon SNS host (`sns.<region>.amazonaws.com`), with no user-info or port.
    - `SubscribeURL` must be a `ConfirmSubscription` call for the configured topic.
    - The unsigned message-type header must agree with the signed `Type`.
    - Both fetches use `wp_safe_remote_get` without redirects.
  - **Sites using SES inbound must set `ses_topic_arn`.** Requests are now rejected when no topic is configured (#79).

### Fixed
- **Assigning an unassigned ticket could fatal when broadcasting was enabled.** `BroadcastService` registered for the `escalated_ticket_assigned` hook with its arguments in the wrong order, so a first assignment passed `null` into an `int` parameter (`TypeError`), and other assignments broadcast the old and new agent swapped. A new test checks every `escalated_*` hook registration against the arguments its `do_action` passes (#80).
- **No notification email or global webhook was ever sent.** `NotificationService` was never registered, so nothing listened for ticket and reply events.
  - **Emails:** new-ticket and reply emails now follow the `notification_new_ticket` and `notification_ticket_reply` settings and the notification sender name and address.
  - **Global `webhook_url`:** it receives ticket created, updated, status changed, assigned, unassigned and department changed, `reply.created` and `sla.breached`. Internal notes are never posted.
  - **Safety:** failures no longer break the ticket operation, and requests use `wp_safe_remote_post` (#81).
- **Several advertised workflow triggers and actions did nothing.**
  - **Triggers:** `ticket.priority_changed`, `reply.agent_reply`, `sla.warning` and `sla.breached` now run their workflows. `sla.warning` runs once per warning even though the SLA check repeats every minute.
  - **Actions:** `set_type` and `send_webhook` are implemented, with `send_webhook` using `wp_safe_remote_post` without redirects. `insert_canned_reply` is now listed, and `send_notification`, which had no implementation, is no longer offered (#82).

## [1.5.0] - 2026-09-12

### Added
- **Configurable database connection.** Define `ESCALATED_DB_NAME` in `wp-config.php` (optionally with `ESCALATED_DB_USER`, `ESCALATED_DB_PASSWORD`, `ESCALATED_DB_HOST` and `ESCALATED_DB_PREFIX`) to keep Escalated's tables outside the WordPress database. Define nothing and the plugin uses the global `$wpdb` — the same instance, so an unconfigured site is unchanged.

  WordPress has no connection registry, so a second database means a second `wpdb`. `Escalated::db()` builds one lazily and reuses it, because `wpdb` connects in its constructor and resolving per query would open a connection per query.

  All 289 `global $wpdb;` bindings across 67 files now resolve through `Escalated::db()`. A test sweeps `includes/` and fails if any file binds the global directly — with a second database configured, a missed one would leave some queries on one connection and some on the other, which reads as data appearing and disappearing.

  WordPress core tables are untouched: the plugin reaches user data through `get_userdata()`, `get_user_by()` and `WP_User_Query`, never raw SQL, and a second test asserts that stays true.

### Added
- SSO service with JWT and SAML support.
- Panel theme toggle (Auto/Light/Dark) in admin settings.
- Ticket type categorization field with filtering.
- Dark mode support across admin and frontend CSS.
- Granular permission tables and seeder on activation.
- "Powered by Escalated" badge with admin toggle.
- Workflow `delay` action — pauses a workflow run for N seconds and resumes the remaining actions via a per-minute WP-Cron sweep. Backed by a new `escalated_deferred_workflow_jobs` table with a composite `(status, run_at)` index for efficient polling. Existing installs need to reactivate the plugin to pick up the new table.
- Users management admin page (Escalated → Users) — list WordPress users with their Escalated admin/agent roles, search by name/email, paginated 20 per page. Toggle the `escalated_admin` / `escalated_agent` WP roles per user with the same self-demote and admin→agent cascade rules as the Laravel reference (escalated-laravel #94). Gated by the `escalated_user_manage` capability (held by `escalated_admin` and `administrator` roles).
- Two-factor authentication (TOTP + recovery codes), porting the Laravel reference. RFC 6238 TOTP implemented in pure PHP (`hash_hmac('sha1', ...)`, base32-decoded secret) with no external dependency. New `escalated_two_factors` table (one row per user) stores the AES-256-CBC-encrypted secret, a JSON array of SHA-256-hashed single-use recovery codes, and `confirmed_at`. Self-service REST routes under `escalated/v1/admin/two-factor` — status, setup (secret + otpauth URI + recovery codes), confirm, verify (TOTP or recovery code challenge), regenerate recovery codes, and disable — each acting on the authenticating token's user. Existing installs need to reactivate the plugin to pick up the new table.
- Functional knowledge base, porting the Laravel reference. Two new tables — `escalated_article_categories` (self-referencing category tree with slug/position) and `escalated_articles` (draft/published status with `published_at`, unique slug, optional category + author, and view/helpful counters) — replace the never-registered `escalated_article` custom post type the widget previously queried (it always returned nothing). Admin CRUD REST routes under `escalated/v1/admin/kb/articles` and `escalated/v1/admin/kb/categories`, gated by the existing `escalated_kb_view`/`_create`/`_edit`/`_delete` capabilities (no new capability added). Public widget endpoints (`/widget/articles`, `/widget/articles/{slug}`, and a new `/widget/articles/{slug}/feedback`) now read published articles from these tables, increment the view counter, return related articles, and record helpful/not-helpful feedback when enabled. `Activator::maybe_upgrade()` creates the tables on version bump — existing installs pick them up automatically on upgrade.

### Changed
- License changed from GPL-2.0-or-later to MIT.
- Translations now load from the central `escalated-dev/locale` Composer package (`vendor/escalated-dev/locale/languages/escalated-{locale}.mo`) with optional site-level overrides from `languages/overrides/escalated-{locale}.mo`. Falls back to the in-tree `languages/` dir when the central package is not installed.

### Fixed
- Validate priority against allowed enum values in ticket creation.
- Update activator tests for granular permission capabilities.
- Replace broken install-wp-tests.sh with direct WP test suite download.

## [1.0.1] - 2026-02-16

### Fixed
- Static method call, missing column, and table existence test.
- API token column name and test assertions.
- Composer platform constraint for PHP 8.1 CI compatibility.
- PHPUnit downgraded to ^9.6 for WordPress test suite compatibility.
- PHPUnit 10 test discovery and activator schema issues.
- Fallback autoloader for plugin class naming conventions.
- WP test suite setup made non-interactive in CI.
- Plugin activation run in test bootstrap before test transactions.

## [1.0.0] - 2026-02-15

### Added
- Full-featured helpdesk and ticketing system for WordPress.
- Ticket management with threaded conversations, internal notes, and activity timeline.
- Custom support roles: escalated_admin and escalated_agent.
- Department-based routing and assignment workflows.
- SLA policies with first-response and resolution targets.
- Automated escalation rules and scheduled SLA checks.
- Customer-facing frontend ticket pages via shortcodes.
- Guest ticket submission and secure guest ticket access.
- Inbound email ingestion via Mailgun, Postmark, and Amazon SES webhooks.
- Canned responses, macros, and tag management.
- Bearer token REST API with per-token abilities and rate limiting.
- Attachment support with configurable upload limits.
- Satisfaction ratings and reporting views.
- Release workflow and README download links.
