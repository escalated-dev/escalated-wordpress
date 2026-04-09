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
  <b>Português (BR)</b> •
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

Um sistema completo de helpdesk e tickets para WordPress com suporte a múltiplos papéis, rastreamento de SLA, regras de escalonamento, processamento de e-mail de entrada, macros e uma REST API. Nenhum serviço externo necessário.

> **[escalated.dev](https://escalated.dev)** — Saiba mais, veja demos e compare as opções Cloud vs Auto-hospedado.

## Screenshots

| Lista de Tickets | Detalhe do Ticket |
|:-----------:|:-------------:|
| ![Lista de Tickets](screenshots/results/ticket-list.png) | ![Detalhe do Ticket](screenshots/results/ticket-detail.png) |

| Departamentos | Políticas SLA |
|:-----------:|:------------:|
| ![Departamentos](screenshots/results/departments.png) | ![Políticas SLA](screenshots/results/sla-policies.png) |

| Relatórios | Configurações |
|:-------:|:--------:|
| ![Relatórios](screenshots/results/reports.png) | ![Configurações](screenshots/results/settings.png) |

| Automações | Macros |
|:-----------:|:------:|
| ![Automações](screenshots/results/automations.png) | ![Macros](screenshots/results/macros.png) |

> As capturas de tela são geradas automaticamente via Playwright a cada release. Veja `.github/workflows/screenshots.yml`.

## Download

[![Download Latest Release](https://img.shields.io/badge/Download-Latest%20Release-2ea44f?style=for-the-badge&logo=github)](https://github.com/escalated-dev/escalated-wordpress/releases/latest)

- Último pacote do plugin: [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip)
- Todas as releases: [Releases](https://github.com/escalated-dev/escalated-wordpress/releases)

## Recursos

- Gerenciamento de tickets com conversas em threads, notas internas e timeline de atividades.
- Papéis de suporte personalizados: `escalated_admin` e `escalated_agent`.
- Roteamento baseado em departamentos e fluxos de atribuição.
- Políticas SLA com metas de primeira resposta e resolução.
- Regras de escalonamento automatizadas e verificações SLA agendadas.
- Páginas de tickets frontend para clientes via shortcodes.
- Envio de tickets por convidados e acesso seguro a tickets de convidados.
- Ingestão de e-mails de entrada via webhooks do Mailgun, Postmark e Amazon SES.
- Respostas prontas, macros e gerenciamento de tags.
- REST API com token Bearer, permissões por token e limitação de taxa.
- Suporte a anexos com limites de upload configuráveis.
- Avaliações de satisfação e visualizações de relatórios.

## Requisitos

- WordPress `6.0+`
- PHP `8.1+`

## Instalação

1. Coloque este plugin no diretório de plugins do WordPress:
   - `wp-content/plugins/escalated`
2. Ative **Escalated** na tela de Plugins do WordPress.
3. Vá para **Escalated** no wp-admin e configure:
   - Departamentos
   - Políticas SLA
   - Regras de Escalonamento
   - Configurações

## Frontend Shortcodes

Use estes shortcodes nas páginas do WordPress:

- `[escalated_tickets]` - Lista de tickets do solicitante logado.
- `[escalated_create_ticket]` - Formulário de novo ticket para solicitantes logados.
- `[escalated_view_ticket]` - Visualização detalhada do ticket:
  - Usuários logados: espera `?ticket=ESC-123`
  - Convidados: espera `?guest_token=<token>`
- `[escalated_guest_create]` - Formulário de criação de ticket para convidados (se habilitado nas configurações).

## REST API

- Namespace: `/wp-json/escalated/v1`
- Auth: `Authorization: Bearer <api-token>`
- Limite de taxa padrão: `60` requisições/minuto por token (configurável via configuração `api_rate_limit`)

Grupos de rotas principais:

- `/auth/validate`
- `/tickets`
- `/departments`
- `/tags`
- `/canned-responses`
- `/macros`
- `/agents`
- `/dashboard`
- `/admin/api-tokens`

## Webhooks de E-mail de Entrada

Padrão de rota de entrada:

- `POST /wp-json/escalated/v1/inbound/{adapter}`

Adaptadores suportados:

- `mailgun`
- `postmark`
- `ses`

## Tarefas Agendadas (WP-Cron)

Na ativação, o Escalated agenda:

- `escalated_check_sla` (a cada minuto)
- `escalated_evaluate_escalations` (a cada 5 minutos)
- `escalated_auto_close` (diário)
- `escalated_purge_activities` (semanal)

## Desenvolvimento

Instalar dependências:

```bash
composer install
```

Executar testes (suite de testes do WordPress necessária):

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Se necessário, defina `WP_TESTS_DIR` com o caminho da biblioteca de testes local do WordPress antes de executar o PHPUnit.

## Também Disponível Para

- **[Escalated for Laravel](https://github.com/escalated-dev/escalated-laravel)** — Pacote Composer para Laravel
- **[Escalated for Rails](https://github.com/escalated-dev/escalated-rails)** — Engine Ruby on Rails
- **[Escalated for Django](https://github.com/escalated-dev/escalated-django)** — Aplicação reutilizável Django
- **[Escalated for AdonisJS](https://github.com/escalated-dev/escalated-adonis)** — Pacote AdonisJS v6
- **[Escalated for Filament](https://github.com/escalated-dev/escalated-filament)** — Plugin painel admin Filament v3
- **[Escalated for WordPress](https://github.com/escalated-dev/escalated-wordpress)** — Plugin WordPress (você está aqui)
- **[Shared Frontend](https://github.com/escalated-dev/escalated)** — Componentes UI Vue 3 + Inertia.js

## Licença

MIT
