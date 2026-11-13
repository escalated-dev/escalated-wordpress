<p align="center"><sub>
  <a href="docs/translations/README.ar.md">العربية</a> ·
  <a href="docs/translations/README.de.md">Deutsch</a> ·
  <b>English</b> ·
  <a href="docs/translations/README.es.md">Español</a> ·
  <a href="docs/translations/README.fr.md">Français</a> ·
  <a href="docs/translations/README.it.md">Italiano</a> ·
  <a href="docs/translations/README.ja.md">日本語</a> ·
  <a href="docs/translations/README.ko.md">한국어</a> ·
  <a href="docs/translations/README.nl.md">Nederlands</a> ·
  <a href="docs/translations/README.pl.md">Polski</a> ·
  <a href="docs/translations/README.pt-BR.md">Português (BR)</a> ·
  <a href="docs/translations/README.ru.md">Русский</a> ·
  <a href="docs/translations/README.tr.md">Türkçe</a> ·
  <a href="docs/translations/README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  Run a complete helpdesk inside WordPress.<br>
  Tickets, SLAs, escalation rules, inbound email and a customer portal, managed from wp-admin with no external services.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Website</b></a> ·
  <a href="https://escalated.dev/docs"><b>Docs</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="CHANGELOG.md"><b>Changelog</b></a>
</p>

<br>

<img src="screenshots/results/ticket-list.png" alt="The Escalated ticket list in wp-admin with filters for status, priority, department and agent" width="100%">

## What it does

<table>
  <tr>
    <td width="40%">
      <h3>Tickets your agents handle in wp-admin</h3>
      Threaded conversations, internal notes, canned responses and macros on the ticket screen. Tickets also arrive by email through Mailgun, Postmark or Amazon SES.
    </td>
    <td width="60%">
      <img src="screenshots/results/ticket-detail.png" alt="A ticket open in wp-admin with the conversation and reply form">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="screenshots/results/sla-policies.png" alt="The SLA policies screen with first-response and resolution targets">
    </td>
    <td width="40%">
      <h3>SLAs and escalation rules that run on their own</h3>
      First-response and resolution targets, rules that escalate tickets when they slip, and SLA checks scheduled through WP-Cron.
    </td>
  </tr>
</table>

## Features

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Threaded conversations and internal notes
- Activity timeline on every ticket
- Canned responses, macros and tags
- Merge duplicate tickets
- Attachments with upload limits

</td>
<td width="33%" valign="top">

**SLAs and routing**
- Department routing and assignment
- First-response and resolution targets
- Escalation rules and scheduled SLA checks
- Event-driven workflows in wp-admin or REST

</td>
<td width="33%" valign="top">

**Customers**
- Frontend ticket pages via shortcodes
- Guest submission and secure guest access
- Satisfaction ratings

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Roles and security**
- Admin, agent and light-agent roles
- Optional per-route permissions
- Optional TOTP two-factor for staff
- SAML 2.0, OAuth 2 / OIDC or JWT sign-in

</td>
<td width="33%" valign="top">

**Email and webhooks**
- Inbound from Mailgun, Postmark and Amazon SES
- Several webhook endpoints with own events
- Delivery log and retries

</td>
<td width="33%" valign="top">

**API and reports**
- Bearer-token REST API with abilities
- Per-token rate limiting
- Reporting views

</td>
</tr>
</table>

<p align="center"><sub><a href="docs/features.md">Every feature in detail</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Quick start

1. Download [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) from the latest release.
2. Unzip it into your plugins directory, so the plugin sits in `wp-content/plugins/escalated`.
3. Activate **Escalated** from the WordPress Plugins screen.
4. Go to **Escalated** in wp-admin and set up departments, SLA policies, escalation rules and settings.

Needs WordPress 6.0+ and PHP 8.1+. The [installation guide](docs/installation.md) has the details, and [customization](docs/customization.md) lists the shortcodes for your customer-facing pages.

## Learn more

- [All features](docs/features.md) · [Installation](docs/installation.md) · [Screenshots](docs/screenshots.md)
- [Shortcodes, ticket subjects and translations](docs/customization.md) · [REST API and ticket merge](docs/rest-api.md)
- [Access control](docs/access-control.md) · [Ticket access and routing](docs/ticket-access-and-routing.md) · [Workflows](docs/workflows.md)
- [Inbound email](docs/inbound-email.md) · [Webhooks](docs/webhooks.md) · [Guest access](docs/guest-access.md)
- [Embedding the widget](docs/embedding-the-widget.md) · [Embedding the admin](docs/embedding-admin.md)
- [Scheduled tasks and database connection](docs/operations.md) · [Newsletters](docs/newsletters.md) · [Development](docs/development.md)

Escalated is also available for [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) and [Filament](https://github.com/escalated-dev/escalated-filament). The UI for the other backends lives in [escalated](https://github.com/escalated-dev/escalated).

## License

MIT. Copyright (c) 2026 Escalated.dev. See [LICENSE](LICENSE).
