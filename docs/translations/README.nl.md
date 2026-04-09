<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <b>Nederlands</b> •
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

Een volledig helpdesk- en ticketsysteem voor WordPress met multi-rol ondersteuning, SLA-tracking, escalatieregels, inkomende e-mailverwerking, macro's en een REST API. Geen externe diensten vereist.

> **[escalated.dev](https://escalated.dev)** — Meer informatie, demo's bekijken en Cloud vs Zelf-gehost opties vergelijken.

## Screenshots

| Ticketlijst | Ticketdetail |
|:-----------:|:-------------:|
| ![Ticketlijst](screenshots/results/ticket-list.png) | ![Ticketdetail](screenshots/results/ticket-detail.png) |

| Afdelingen | SLA-beleid |
|:-----------:|:------------:|
| ![Afdelingen](screenshots/results/departments.png) | ![SLA-beleid](screenshots/results/sla-policies.png) |

| Rapporten | Instellingen |
|:-------:|:--------:|
| ![Rapporten](screenshots/results/reports.png) | ![Instellingen](screenshots/results/settings.png) |

| Automatiseringen | Macro's |
|:-----------:|:------:|
| ![Automatiseringen](screenshots/results/automations.png) | ![Macro's](screenshots/results/macros.png) |

> Screenshots worden bij elke release automatisch gegenereerd via Playwright. Zie `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Laatste plugin-pakket: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Alle releases: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Functies

- Ticketbeheer met threaded gesprekken, interne notities en activiteitstijdlijn.
- Aangepaste ondersteuningsrollen: `escalated_admin` en `escalated_agent`.
- Afdelingsgebaseerde routering en toewijzingsworkflows.
- SLA-beleid met doelen voor eerste reactie en oplossing.
- Geautomatiseerde escalatieregels en geplande SLA-controles.
- Klantgerichte frontend-ticketpagina's via shortcodes.
- Gastticket-indiening en veilige gasttickettoegang.
- Inkomende e-mailverwerking via Mailgun-, Postmark- en Amazon SES-webhooks.
- Standaardantwoorden, macro's en tagbeheer.
- Bearer-token REST API met per-token machtigingen en snelheidsbeperking.
- Bijlageondersteuning met configureerbare uploadlimieten.
- Tevredenheidsbeoordelingen en rapportageweergaven.

## Vereisten

- WordPress `6.0+`
- PHP `8.1+`

## Installatie

1. Plaats deze plugin in uw WordPress-pluginmap:
   - `wp-content/plugins/escalated`
2. Activeer **Escalated** via het WordPress Plugins-scherm.
3. Ga naar **Escalated** in wp-admin en configureer:
   - Afdelingen
   - SLA-beleid
   - Escalatieregels
   - Instellingen

## Frontend Shortcodes

Gebruik deze shortcodes op WordPress-pagina's:

- `[escalated_tickets]` - Ticketlijst van de ingelogde aanvrager.
- `[escalated_create_ticket]` - Nieuw ticketformulier voor ingelogde aanvragers.
- `[escalated_view_ticket]` - Ticketdetailweergave:
  - Ingelogde gebruikers: verwacht `?ticket=ESC-123`
  - Gasten: verwacht `?guest_token=<token>`
- `[escalated_guest_create]` - Gastticket-aanmaakformulier (indien ingeschakeld in instellingen).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Standaard snelheidslimiet: `60` verzoeken/minuut per token (configureerbaar via de instelling `api_rate_limit`)

Belangrijkste routegroepen:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Inkomende e-mail webhooks

Inkomend routepatroon:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Ondersteunde adapters:

- `mailgun`
- `postmark`
- `ses`

## Geplande taken (WP-Cron)

Bij activering plant Escalated:

- `escalated_check_sla` (elke minuut)
- `escalated_evaluate_escalations` (elke 5 minuten)
- `escalated_auto_close` (dagelijks)
- `escalated_purge_activities` (wekelijks)

## Ontwikkeling

Afhankelijkheden installeren:

```bash
composer install
```

Tests uitvoeren (WordPress-testsuite vereist):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Stel indien nodig `WP_TESTS_DIR` in op het pad van uw lokale WordPress-testbibliotheek voordat u PHPUnit uitvoert.

## Ook Beschikbaar Voor

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Laravel Composer-pakket
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Ruby on Rails Engine
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Herbruikbare Django-app
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — AdonisJS v6 pakket
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Filament v3 adminpaneel-plugin
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — WordPress-plugin (u bent hier)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Vue 3 + Inertia.js UI-componenten

## Licentie

MIT
