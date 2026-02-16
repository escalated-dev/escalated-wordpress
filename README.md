# escalated-wordpress

A full-featured helpdesk and ticketing system for WordPress with multi-role support, SLA tracking, escalation rules, inbound email processing, macros, and a REST API.

## Features

- Ticket management with threaded conversations, internal notes, and activity timeline.
- Custom support roles: `escalated_admin` and `escalated_agent`.
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

## Requirements

- WordPress `6.0+`
- PHP `8.1+`

## Installation

1. Place this plugin in your WordPress plugins directory:
   - `wp-content/plugins/escalated`
2. Activate **Escalated** from the WordPress Plugins screen.
3. Go to **Escalated** in wp-admin and configure:
   - Departments
   - SLA Policies
   - Escalation Rules
   - Settings

## Frontend Shortcodes

Use these shortcodes on WordPress pages:

- `[escalated_tickets]` - Logged-in requester ticket list.
- `[escalated_create_ticket]` - Logged-in requester new ticket form.
- `[escalated_view_ticket]` - Ticket detail view:
  - Logged-in users: expects `?ticket=ESC-123`
  - Guests: expects `?guest_token=<token>`
- `[escalated_guest_create]` - Guest ticket creation form (if enabled in settings).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Default rate limit: `60` requests/minute per token (configurable via `api_rate_limit` setting)

Main route groups:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Inbound Email Webhooks

Inbound route pattern:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Supported adapters:

- `mailgun`
- `postmark`
- `ses`

## Scheduled Tasks (WP-Cron)

On activation, Escalated schedules:

- `escalated_check_sla` (every minute)
- `escalated_evaluate_escalations` (every 5 minutes)
- `escalated_auto_close` (daily)
- `escalated_purge_activities` (weekly)

## Development

Install dependencies:

```bash
composer install
```

Run tests (WordPress test suite required):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

If needed, set `WP_TESTS_DIR` to your local WordPress tests library path before running PHPUnit.

## License

GPL-2.0-or-later. See `readme.txt` and plugin headers for details.
