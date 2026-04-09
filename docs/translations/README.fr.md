<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <b>Français</b> •
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

Un système complet de helpdesk et de tickets pour WordPress avec support multi-rôles, suivi SLA, règles d'escalade, traitement des e-mails entrants, macros et une REST API. Aucun service externe requis.

> **[escalated.dev](https://escalated.dev)** — En savoir plus, voir les démos et comparer les options Cloud vs Auto-hébergé.

## Screenshots

| Liste des tickets | Détail du ticket |
|:-----------:|:-------------:|
| ![Liste des tickets](screenshots/results/ticket-list.png) | ![Détail du ticket](screenshots/results/ticket-detail.png) |

| Départements | Politiques SLA |
|:-----------:|:------------:|
| ![Départements](screenshots/results/departments.png) | ![Politiques SLA](screenshots/results/sla-policies.png) |

| Rapports | Paramètres |
|:-------:|:--------:|
| ![Rapports](screenshots/results/reports.png) | ![Paramètres](screenshots/results/settings.png) |

| Automatisations | Macros |
|:-----------:|:------:|
| ![Automatisations](screenshots/results/automations.png) | ![Macros](screenshots/results/macros.png) |

> Les captures d'écran sont générées automatiquement via Playwright à chaque release. Voir `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Dernier package du plugin : [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Toutes les releases : [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Fonctionnalités

- Gestion des tickets avec conversations en fils, notes internes et chronologie d'activité.
- Rôles de support personnalisés : `escalated_admin` et `escalated_agent`.
- Routage par département et workflows d'assignation.
- Politiques SLA avec objectifs de première réponse et de résolution.
- Règles d'escalade automatisées et vérifications SLA programmées.
- Pages de tickets frontend pour les clients via shortcodes.
- Soumission de tickets invité et accès sécurisé aux tickets invité.
- Ingestion d'e-mails entrants via les webhooks Mailgun, Postmark et Amazon SES.
- Réponses pré-enregistrées, macros et gestion des tags.
- REST API avec token Bearer, permissions par token et limitation de débit.
- Support des pièces jointes avec limites de téléchargement configurables.
- Évaluations de satisfaction et vues de rapports.

## Prérequis

- WordPress `6.0+`
- PHP `8.1+`

## Installation

1. Placez ce plugin dans votre répertoire de plugins WordPress :
   - `wp-content/plugins/escalated`
2. Activez **Escalated** depuis l'écran Plugins de WordPress.
3. Allez dans **Escalated** dans wp-admin et configurez :
   - Départements
   - Politiques SLA
   - Règles d'escalade
   - Paramètres

## Frontend Shortcodes

Utilisez ces shortcodes sur les pages WordPress :

- `[escalated_tickets]` - Liste des tickets du demandeur connecté.
- `[escalated_create_ticket]` - Formulaire de nouveau ticket pour les demandeurs connectés.
- `[escalated_view_ticket]` - Vue détaillée du ticket :
  - Utilisateurs connectés : attend `?ticket=ESC-123`
  - Invités : attend `?guest_token=<token>`
- `[escalated_guest_create]` - Formulaire de création de ticket invité (si activé dans les paramètres).

## REST API

- Namespace : `/wp-json/escalated/v1`
- Auth : `Authorization: Bearer <api-token>`
- Limite de débit par défaut : `60` requêtes/minute par token (configurable via le paramètre `api_rate_limit`)

Groupes de routes principaux :

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Webhooks d'e-mails entrants

Modèle de route entrante :

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Adaptateurs supportés :

- `mailgun`
- `postmark`
- `ses`

## Tâches programmées (WP-Cron)

À l'activation, Escalated programme :

- `escalated_check_sla` (chaque minute)
- `escalated_evaluate_escalations` (toutes les 5 minutes)
- `escalated_auto_close` (quotidien)
- `escalated_purge_activities` (hebdomadaire)

## Développement

Installer les dépendances :

```bash
composer install
```

Exécuter les tests (suite de tests WordPress requise) :

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Si nécessaire, définissez `WP_TESTS_DIR` avec le chemin de votre bibliothèque de tests WordPress locale avant d'exécuter PHPUnit.

## Également Disponible Pour

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Package Composer pour Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Engine Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Application réutilisable Django
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — Package AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Plugin panneau d'administration Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — Plugin WordPress (vous êtes ici)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Composants UI Vue 3 + Inertia.js

## Licence

MIT
