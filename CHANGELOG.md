# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

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
