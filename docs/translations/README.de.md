<p align="center">
  <a href="README.ar.md">العربية</a> •
  <b>Deutsch</b> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
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

Ein umfassendes Helpdesk- und Ticketsystem für WordPress mit Mehrrollen-Unterstützung, SLA-Tracking, Eskalationsregeln, eingehender E-Mail-Verarbeitung, Makros und einer REST API. Keine externen Dienste erforderlich.

> **[escalated.dev](https://escalated.dev)** — Erfahren Sie mehr, sehen Sie Demos und vergleichen Sie Cloud- vs. Self-Hosted-Optionen.

## Screenshots

| Ticketliste | Ticketdetail |
|:-----------:|:-------------:|
| ![Ticketliste](screenshots/results/ticket-list.png) | ![Ticketdetail](screenshots/results/ticket-detail.png) |

| Abteilungen | SLA-Richtlinien |
|:-----------:|:------------:|
| ![Abteilungen](screenshots/results/departments.png) | ![SLA-Richtlinien](screenshots/results/sla-policies.png) |

| Berichte | Einstellungen |
|:-------:|:--------:|
| ![Berichte](screenshots/results/reports.png) | ![Einstellungen](screenshots/results/settings.png) |

| Automatisierungen | Makros |
|:-----------:|:------:|
| ![Automatisierungen](screenshots/results/automations.png) | ![Makros](screenshots/results/macros.png) |

> Screenshots werden bei jedem Release automatisch per Playwright generiert. Siehe `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Aktuelles Plugin-Paket: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Alle Releases: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Funktionen

- Ticketverwaltung mit Thread-Konversationen, internen Notizen und Aktivitäts-Timeline.
- Benutzerdefinierte Support-Rollen: `escalated_admin` und `escalated_agent`.
- Abteilungsbasiertes Routing und Zuweisungs-Workflows.
- SLA-Richtlinien mit Erstantwort- und Lösungszielen.
- Automatisierte Eskalationsregeln und geplante SLA-Prüfungen.
- Kundenorientierte Frontend-Ticketseiten über Shortcodes.
- Gast-Ticketeinreichung und sicherer Gast-Ticketzugang.
- Eingehende E-Mail-Verarbeitung über Mailgun-, Postmark- und Amazon SES-Webhooks.
- Vordefinierte Antworten, Makros und Tag-Verwaltung.
- Bearer-Token-REST API mit tokenbasierten Berechtigungen und Rate Limiting.
- Anhang-Unterstützung mit konfigurierbaren Upload-Limits.
- Zufriedenheitsbewertungen und Berichtsansichten.

## Voraussetzungen

- WordPress `6.0+`
- PHP `8.1+`

## Installation

1. Platzieren Sie dieses Plugin in Ihrem WordPress-Plugin-Verzeichnis:
   - `wp-content/plugins/escalated`
2. Aktivieren Sie **Escalated** über den WordPress-Plugin-Bildschirm.
3. Gehen Sie zu **Escalated** in wp-admin und konfigurieren Sie:
   - Abteilungen
   - SLA-Richtlinien
   - Eskalationsregeln
   - Einstellungen

## Frontend Shortcodes

Verwenden Sie diese Shortcodes auf WordPress-Seiten:

- `[escalated_tickets]` - Ticketliste des angemeldeten Anfragenden.
- `[escalated_create_ticket]` - Neues Ticket-Formular für angemeldete Anfragende.
- `[escalated_view_ticket]` - Ticket-Detailansicht:
  - Angemeldete Benutzer: erwartet `?ticket=ESC-123`
  - Gäste: erwartet `?guest_token=<token>`
- `[escalated_guest_create]` - Gast-Ticketerstellungsformular (wenn in den Einstellungen aktiviert).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Standard-Rate-Limit: `60` Anfragen/Minute pro Token (konfigurierbar über die Einstellung `api_rate_limit`)

Hauptroutengruppen:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Eingehende E-Mail-Webhooks

Muster für eingehende Routen:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Unterstützte Adapter:

- `mailgun`
- `postmark`
- `ses`

## Geplante Aufgaben (WP-Cron)

Bei der Aktivierung plant Escalated:

- `escalated_check_sla` (jede Minute)
- `escalated_evaluate_escalations` (alle 5 Minuten)
- `escalated_auto_close` (täglich)
- `escalated_purge_activities` (wöchentlich)

## Entwicklung

Abhängigkeiten installieren:

```bash
composer install
```

Tests ausführen (WordPress-Testsuite erforderlich):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Falls nötig, setzen Sie `WP_TESTS_DIR` auf den Pfad Ihrer lokalen WordPress-Testbibliothek, bevor Sie PHPUnit ausführen.

## Auch verfügbar für

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composer-Paket
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails Engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Wiederverwendbare Django-App
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6 Paket
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3 Admin-Panel-Plugin
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPress-Plugin (Sie sind hier)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UI-Komponenten

## Lizenz

MIT
