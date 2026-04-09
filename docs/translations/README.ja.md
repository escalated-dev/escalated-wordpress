<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <b>日本語</b> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for WordPress

[![Tests](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml/badge.svg)](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml)
[![Latest Release](https://img.shields.io/github/v/release/escalated-dev/escalated-wordpress)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.0-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A full-featured helpdesk and ticketing system for WordPress with multi-role support, SLA tracking, escalation rules, inbound email processing, macros, and a REST API. No external services required.

> **[escalated.dev](https://escalated.dev)** — Learn more, view demos, and compare Cloud vs Self-Hosted options.

## Screenshots

| Ticket List | Ticket Detail |
|:-----------:|:-------------:|
| ![Ticket List](screenshots/results/ticket-list.png) | ![Ticket Detail](screenshots/results/ticket-detail.png) |

| Departments | SLA Policies |
|:-----------:|:------------:|
| ![Departments](screenshots/results/departments.png) | ![SLA Policies](screenshots/results/sla-policies.png) |

| Reports | Settings |
|:-------:|:--------:|
| ![Reports](screenshots/results/reports.png) | ![Settings](screenshots/results/settings.png) |

| Automations | Macros |
|:-----------:|:------:|
| ![Automations](screenshots/results/automations.png) | ![Macros](screenshots/results/macros.png) |

> Screenshots are auto-generated via Playwright on every release. See `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Latest plugin package: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- All releases: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## 機能

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

## 要件

- WordPress `6.0+`
- PHP `8.1+`

## インストール

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

## 他のフレームワーク向け

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composer package
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Django reusable app
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6 package
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3 admin panel plugin
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPress plugin (you are here)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UI components

## ライセンス

MIT
