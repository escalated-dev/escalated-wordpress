<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <b>Español</b> •
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

Un sistema completo de mesa de ayuda y tickets de soporte para WordPress con soporte multirol, seguimiento de SLA, reglas de escalamiento, procesamiento de correo entrante, macros y una REST API. No requiere servicios externos.

> **[escalated.dev](https://escalated.dev)** — Aprende más, ve demos y compara las opciones Cloud vs Auto-hospedado.

## Screenshots

| Lista de Tickets | Detalle del Ticket |
|:-----------:|:-------------:|
| ![Lista de Tickets](screenshots/results/ticket-list.png) | ![Detalle del Ticket](screenshots/results/ticket-detail.png) |

| Departamentos | Políticas SLA |
|:-----------:|:------------:|
| ![Departamentos](screenshots/results/departments.png) | ![Políticas SLA](screenshots/results/sla-policies.png) |

| Reportes | Configuración |
|:-------:|:--------:|
| ![Reportes](screenshots/results/reports.png) | ![Configuración](screenshots/results/settings.png) |

| Automatizaciones | Macros |
|:-----------:|:------:|
| ![Automatizaciones](screenshots/results/automations.png) | ![Macros](screenshots/results/macros.png) |

> Las capturas de pantalla se generan automáticamente mediante Playwright en cada lanzamiento. Ver `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Último paquete del plugin: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Todos los lanzamientos: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Características

- Gestión de tickets con conversaciones en hilos, notas internas y línea de tiempo de actividad.
- Roles de soporte personalizados: `escalated_admin` y `escalated_agent`.
- Enrutamiento basado en departamentos y flujos de asignación.
- Políticas SLA con objetivos de primera respuesta y resolución.
- Reglas de escalamiento automatizadas y verificaciones SLA programadas.
- Páginas de tickets para clientes en el frontend mediante shortcodes.
- Envío de tickets como invitado y acceso seguro a tickets de invitados.
- Ingesta de correo entrante mediante webhooks de Mailgun, Postmark y Amazon SES.
- Respuestas predefinidas, macros y gestión de etiquetas.
- REST API con token Bearer, permisos por token y limitación de tasa.
- Soporte de archivos adjuntos con límites de carga configurables.
- Calificaciones de satisfacción y vistas de reportes.

## Requisitos

- WordPress `6.0+`
- PHP `8.1+`

## Instalación

1. Coloca este plugin en tu directorio de plugins de WordPress:
   - `wp-content/plugins/escalated`
2. Activa **Escalated** desde la pantalla de Plugins de WordPress.
3. Ve a **Escalated** en wp-admin y configura:
   - Departamentos
   - Políticas SLA
   - Reglas de Escalamiento
   - Configuración

## Frontend Shortcodes

Usa estos shortcodes en las páginas de WordPress:

- `[escalated_tickets]` - Lista de tickets del solicitante autenticado.
- `[escalated_create_ticket]` - Formulario de nuevo ticket para solicitantes autenticados.
- `[escalated_view_ticket]` - Vista detallada del ticket:
  - Usuarios autenticados: espera `?ticket=ESC-123`
  - Invitados: espera `?guest_token=<token>`
- `[escalated_guest_create]` - Formulario de creación de ticket para invitados (si está habilitado en la configuración).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Límite de tasa predeterminado: `60` solicitudes/minuto por token (configurable mediante la opción `api_rate_limit`)

Grupos de rutas principales:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Webhooks de Correo Entrante

Patrón de ruta entrante:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Adaptadores soportados:

- `mailgun`
- `postmark`
- `ses`

## Tareas Programadas (WP-Cron)

Al activarse, Escalated programa:

- `escalated_check_sla` (cada minuto)
- `escalated_evaluate_escalations` (cada 5 minutos)
- `escalated_auto_close` (diario)
- `escalated_purge_activities` (semanal)

## Desarrollo

Instalar dependencias:

```bash
composer install
```

Ejecutar pruebas (se requiere la suite de pruebas de WordPress):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Si es necesario, establece `WP_TESTS_DIR` con la ruta a tu biblioteca local de pruebas de WordPress antes de ejecutar PHPUnit.

## También Disponible Para

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Paquete Composer para Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Engine para Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Aplicación reutilizable para Django
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — Paquete para AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Plugin para panel de administración Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — Plugin para WordPress (estás aquí)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Componentes UI Vue 3 + Inertia.js

## Licencia

MIT
