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

### Changed
- License changed from GPL-2.0-or-later to MIT.

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
