# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

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
