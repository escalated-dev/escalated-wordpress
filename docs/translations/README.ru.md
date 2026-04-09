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
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <b>Русский</b> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for WordPress

[![Tests](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml/badge.svg)](https://github.com/escalated-dev/escalated-wordpress/actions/workflows/run-tests.yml)
[![Latest Release](https://img.shields.io/github/v/release/escalated-dev/escalated-wordpress)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/wordpress-%3E%3D6.0-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Полнофункциональная система helpdesk и тикетов для WordPress с поддержкой множества ролей, отслеживанием SLA, правилами эскалации, обработкой входящей почты, макросами и REST API. Внешние сервисы не требуются.

> **[escalated.dev](https://escalated.dev)** — Узнайте больше, посмотрите демо и сравните варианты Cloud и Self-Hosted.

## Screenshots

| Список тикетов | Детали тикета |
|:-----------:|:-------------:|
| ![Список тикетов](screenshots/results/ticket-list.png) | ![Детали тикета](screenshots/results/ticket-detail.png) |

| Отделы | Политики SLA |
|:-----------:|:------------:|
| ![Отделы](screenshots/results/departments.png) | ![Политики SLA](screenshots/results/sla-policies.png) |

| Отчёты | Настройки |
|:-------:|:--------:|
| ![Отчёты](screenshots/results/reports.png) | ![Настройки](screenshots/results/settings.png) |

| Автоматизации | Макросы |
|:-----------:|:------:|
| ![Автоматизации](screenshots/results/automations.png) | ![Макросы](screenshots/results/macros.png) |

> Скриншоты автоматически генерируются через Playwright при каждом релизе. См. `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Последний пакет плагина: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Все релизы: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Возможности

- Управление тикетами с цепочками разговоров, внутренними заметками и хронологией активности.
- Пользовательские роли поддержки: `escalated_admin` и `escalated_agent`.
- Маршрутизация по отделам и рабочие процессы назначения.
- Политики SLA с целями первого ответа и решения.
- Автоматизированные правила эскалации и запланированные проверки SLA.
- Клиентские страницы тикетов на фронтенде через шорткоды.
- Отправка гостевых тикетов и безопасный доступ к гостевым тикетам.
- Приём входящей почты через вебхуки Mailgun, Postmark и Amazon SES.
- Шаблонные ответы, макросы и управление тегами.
- REST API с Bearer-токеном, правами доступа по токену и ограничением частоты запросов.
- Поддержка вложений с настраиваемыми лимитами загрузки.
- Оценки удовлетворённости и представления отчётов.

## Требования

- WordPress `6.0+`
- PHP `8.1+`

## Установка

1. Поместите этот плагин в каталог плагинов WordPress:
   - `wp-content/plugins/escalated`
2. Активируйте **Escalated** на экране Плагинов WordPress.
3. Перейдите в **Escalated** в wp-admin и настройте:
   - Отделы
   - Политики SLA
   - Правила эскалации
   - Настройки

## Фронтенд шорткоды

Используйте эти шорткоды на страницах WordPress:

- `[escalated_tickets]` - Список тикетов авторизованного пользователя.
- `[escalated_create_ticket]` - Форма нового тикета для авторизованных пользователей.
- `[escalated_view_ticket]` - Детальный просмотр тикета:
  - Авторизованные пользователи: ожидает `?ticket=ESC-123`
  - Гости: ожидает `?guest_token=<token>`
- `[escalated_guest_create]` - Форма создания гостевого тикета (если включено в настройках).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Авторизация: `Authorization: Bearer <api-token>`
- Ограничение частоты по умолчанию: `60` запросов/минуту на токен (настраивается через параметр `api_rate_limit`)

Основные группы маршрутов:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Вебхуки входящей почты

Шаблон входящего маршрута:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Поддерживаемые адаптеры:

- `mailgun`
- `postmark`
- `ses`

## Запланированные задачи (WP-Cron)

При активации Escalated планирует:

- `escalated_check_sla` (каждую минуту)
- `escalated_evaluate_escalations` (каждые 5 минут)
- `escalated_auto_close` (ежедневно)
- `escalated_purge_activities` (еженедельно)

## Разработка

Установка зависимостей:

```bash
composer install
```

Запуск тестов (требуется тестовый набор WordPress):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

При необходимости установите `WP_TESTS_DIR` в путь к вашей локальной тестовой библиотеке WordPress перед запуском PHPUnit.

## Также доступно для

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Composer-пакет для Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Engine для Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Переиспользуемое приложение Django
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — Пакет AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Плагин админ-панели Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — Плагин WordPress (вы здесь)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — UI-компоненты Vue 3 + Inertia.js

## Лицензия

MIT
