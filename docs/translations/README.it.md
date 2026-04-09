<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <b>Italiano</b> •
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

Un sistema completo di helpdesk e ticketing per WordPress con supporto multi-ruolo, tracciamento SLA, regole di escalation, elaborazione email in entrata, macro e una REST API. Nessun servizio esterno richiesto.

> **[escalated.dev](https://escalated.dev)** — Scopri di più, guarda le demo e confronta le opzioni Cloud vs Self-Hosted.

## Screenshots

| Lista ticket | Dettaglio ticket |
|:-----------:|:-------------:|
| ![Lista ticket](screenshots/results/ticket-list.png) | ![Dettaglio ticket](screenshots/results/ticket-detail.png) |

| Dipartimenti | Politiche SLA |
|:-----------:|:------------:|
| ![Dipartimenti](screenshots/results/departments.png) | ![Politiche SLA](screenshots/results/sla-policies.png) |

| Report | Impostazioni |
|:-------:|:--------:|
| ![Report](screenshots/results/reports.png) | ![Impostazioni](screenshots/results/settings.png) |

| Automazioni | Macro |
|:-----------:|:------:|
| ![Automazioni](screenshots/results/automations.png) | ![Macro](screenshots/results/macros.png) |

> Gli screenshot vengono generati automaticamente tramite Playwright ad ogni release. Vedi `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Ultimo pacchetto del plugin: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Tutte le release: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Funzionalità

- Gestione ticket con conversazioni in thread, note interne e timeline delle attività.
- Ruoli di supporto personalizzati: `escalated_admin` e `escalated_agent`.
- Instradamento basato sui dipartimenti e workflow di assegnazione.
- Politiche SLA con obiettivi di prima risposta e risoluzione.
- Regole di escalation automatizzate e controlli SLA programmati.
- Pagine ticket frontend per i clienti tramite shortcode.
- Invio ticket ospite e accesso sicuro ai ticket ospite.
- Acquisizione email in entrata tramite webhook Mailgun, Postmark e Amazon SES.
- Risposte predefinite, macro e gestione tag.
- REST API con token Bearer, permessi per token e limitazione della frequenza.
- Supporto allegati con limiti di upload configurabili.
- Valutazioni di soddisfazione e viste report.

## Requisiti

- WordPress `6.0+`
- PHP `8.1+`

## Installazione

1. Posiziona questo plugin nella directory dei plugin di WordPress:
   - `wp-content/plugins/escalated`
2. Attiva **Escalated** dalla schermata Plugin di WordPress.
3. Vai su **Escalated** in wp-admin e configura:
   - Dipartimenti
   - Politiche SLA
   - Regole di escalation
   - Impostazioni

## Frontend Shortcodes

Usa questi shortcode nelle pagine WordPress:

- `[escalated_tickets]` - Lista ticket del richiedente autenticato.
- `[escalated_create_ticket]` - Modulo nuovo ticket per richiedenti autenticati.
- `[escalated_view_ticket]` - Vista dettagliata del ticket:
  - Utenti autenticati: si aspetta `?ticket=ESC-123`
  - Ospiti: si aspetta `?guest_token=<token>`
- `[escalated_guest_create]` - Modulo di creazione ticket ospite (se abilitato nelle impostazioni).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Limite di frequenza predefinito: `60` richieste/minuto per token (configurabile tramite l'impostazione `api_rate_limit`)

Gruppi di route principali:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Webhook email in entrata

Pattern route in entrata:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Adapter supportati:

- `mailgun`
- `postmark`
- `ses`

## Attività programmate (WP-Cron)

All'attivazione, Escalated programma:

- `escalated_check_sla` (ogni minuto)
- `escalated_evaluate_escalations` (ogni 5 minuti)
- `escalated_auto_close` (giornaliero)
- `escalated_purge_activities` (settimanale)

## Sviluppo

Installare le dipendenze:

```bash
composer install
```

Eseguire i test (suite di test WordPress richiesta):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Se necessario, imposta `WP_TESTS_DIR` con il percorso della libreria di test WordPress locale prima di eseguire PHPUnit.

## Disponibile Anche Per

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Pacchetto Composer per Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Engine Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — App riutilizzabile Django
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — Pacchetto AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Plugin pannello admin Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — Plugin WordPress (sei qui)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Componenti UI Vue 3 + Inertia.js

## Licenza

MIT
