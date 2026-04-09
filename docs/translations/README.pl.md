<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <b>Polski</b> •
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

Kompletny system helpdesk i obsługi zgłoszeń dla WordPressa z obsługą wielu ról, śledzeniem SLA, regułami eskalacji, przetwarzaniem poczty przychodzącej, makrami i REST API. Nie wymaga zewnętrznych usług.

> **[escalated.dev](https://escalated.dev)** — Dowiedz się więcej, zobacz demo i porównaj opcje Cloud vs Self-Hosted.

## Screenshots

| Lista zgłoszeń | Szczegóły zgłoszenia |
|:-----------:|:-------------:|
| ![Lista zgłoszeń](screenshots/results/ticket-list.png) | ![Szczegóły zgłoszenia](screenshots/results/ticket-detail.png) |

| Działy | Polityki SLA |
|:-----------:|:------------:|
| ![Działy](screenshots/results/departments.png) | ![Polityki SLA](screenshots/results/sla-policies.png) |

| Raporty | Ustawienia |
|:-------:|:--------:|
| ![Raporty](screenshots/results/reports.png) | ![Ustawienia](screenshots/results/settings.png) |

| Automatyzacje | Makra |
|:-----------:|:------:|
| ![Automatyzacje](screenshots/results/automations.png) | ![Makra](screenshots/results/macros.png) |

> Zrzuty ekranu są generowane automatycznie przez Playwright przy każdym wydaniu. Zobacz `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Najnowszy pakiet wtyczki: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Wszystkie wydania: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Funkcje

- Zarządzanie zgłoszeniami z wątkowymi konwersacjami, notatkami wewnętrznymi i osią czasu aktywności.
- Niestandardowe role wsparcia: `escalated_admin` i `escalated_agent`.
- Routing oparty na działach i przepływy pracy przypisywania.
- Polityki SLA z celami pierwszej odpowiedzi i rozwiązania.
- Zautomatyzowane reguły eskalacji i zaplanowane kontrole SLA.
- Frontendowe strony zgłoszeń dla klientów przez shortcody.
- Składanie zgłoszeń gości i bezpieczny dostęp do zgłoszeń gości.
- Przetwarzanie poczty przychodzącej przez webhooki Mailgun, Postmark i Amazon SES.
- Gotowe odpowiedzi, makra i zarządzanie tagami.
- REST API z tokenem Bearer, uprawnieniami per token i limitowaniem żądań.
- Obsługa załączników z konfigurowalnymi limitami przesyłania.
- Oceny satysfakcji i widoki raportów.

## Wymagania

- WordPress `6.0+`
- PHP `8.1+`

## Instalacja

1. Umieść tę wtyczkę w katalogu wtyczek WordPress:
   - `wp-content/plugins/escalated`
2. Aktywuj **Escalated** z ekranu Wtyczki WordPress.
3. Przejdź do **Escalated** w wp-admin i skonfiguruj:
   - Działy
   - Polityki SLA
   - Reguły eskalacji
   - Ustawienia

## Frontend Shortcodes

Użyj tych shortcodów na stronach WordPress:

- `[escalated_tickets]` - Lista zgłoszeń zalogowanego zgłaszającego.
- `[escalated_create_ticket]` - Formularz nowego zgłoszenia dla zalogowanych zgłaszających.
- `[escalated_view_ticket]` - Widok szczegółów zgłoszenia:
  - Zalogowani użytkownicy: oczekuje `?ticket=ESC-123`
  - Goście: oczekuje `?guest_token=<token>`
- `[escalated_guest_create]` - Formularz tworzenia zgłoszenia gościa (jeśli włączone w ustawieniach).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Autoryzacja: `Authorization: Bearer <api-token>`
- Domyślny limit żądań: `60` żądań/minutę na token (konfigurowalny przez ustawienie `api_rate_limit`)

Główne grupy tras:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Webhooki poczty przychodzącej

Wzorzec trasy przychodzącej:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Obsługiwane adaptery:

- `mailgun`
- `postmark`
- `ses`

## Zaplanowane zadania (WP-Cron)

Przy aktywacji Escalated planuje:

- `escalated_check_sla` (co minutę)
- `escalated_evaluate_escalations` (co 5 minut)
- `escalated_auto_close` (codziennie)
- `escalated_purge_activities` (cotygodniowo)

## Rozwój

Instalacja zależności:

```bash
composer install
```

Uruchomienie testów (wymagany zestaw testów WordPress):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

W razie potrzeby ustaw `WP_TESTS_DIR` na ścieżkę lokalnej biblioteki testów WordPress przed uruchomieniem PHPUnit.

## Dostępne Również Dla

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Pakiet Composer dla Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Engine Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Aplikacja wielokrotnego użytku Django
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — Pakiet AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Wtyczka panelu administracyjnego Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — Wtyczka WordPress (jesteś tutaj)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Komponenty UI Vue 3 + Inertia.js

## Licencja

MIT
