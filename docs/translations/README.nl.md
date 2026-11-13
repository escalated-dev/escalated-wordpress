<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <a href="README.ko.md">한국어</a> ·
  <b>Nederlands</b> ·
  <a href="README.pl.md">Polski</a> ·
  <a href="README.pt-BR.md">Português (BR)</a> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for WordPress</h1>

<p align="center">
  Draai een complete helpdesk binnen WordPress.<br>
  Tickets, SLA's, escalatieregels, inkomende e-mail en een klantportaal, beheerd vanuit wp-admin zonder externe diensten.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Website</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentatie</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Wijzigingslog</b></a>
</p>

<br>

<img src="../../screenshots/results/ticket-list.png" alt="De ticketlijst van Escalated in wp-admin met filters voor status, prioriteit, afdeling en agent" width="100%">

## Wat het doet

<table>
  <tr>
    <td width="40%">
      <h3>Tickets die je agents in wp-admin afhandelen</h3>
      Gesprekken in threads, interne notities, standaardantwoorden en macro's op het ticketscherm. Tickets komen ook binnen per e-mail via Mailgun, Postmark of Amazon SES.
    </td>
    <td width="60%">
      <img src="../../screenshots/results/ticket-detail.png" alt="Een geopend ticket in wp-admin met het gesprek en het antwoordformulier">
    </td>
  </tr>
  <tr>
    <td width="60%">
      <img src="../../screenshots/results/sla-policies.png" alt="Het scherm met SLA-beleid en doelen voor eerste reactie en oplossing">
    </td>
    <td width="40%">
      <h3>SLA's en escalatieregels die vanzelf draaien</h3>
      Doelen voor eerste reactie en oplossing, regels die tickets escaleren als een doel uitloopt, en SLA-controles die via WP-Cron worden ingepland.
    </td>
  </tr>
</table>

## Functies

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Gesprekken in threads en interne notities
- Activiteitentijdlijn bij elk ticket
- Standaardantwoorden, macro's en tags
- Dubbele tickets samenvoegen
- Bijlagen met uploadlimieten

</td>
<td width="33%" valign="top">

**SLA's en routering**
- Routering en toewijzing per afdeling
- Doelen voor eerste reactie en oplossing
- Escalatieregels en ingeplande SLA-controles
- Event-gestuurde workflows in wp-admin of via REST

</td>
<td width="33%" valign="top">

**Klanten**
- Ticketpagina's op de frontend via shortcodes
- Indienen als gast en veilige gasttoegang
- Tevredenheidsbeoordelingen

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Rollen en beveiliging**
- Rollen voor beheerder, agent en light agent
- Optionele rechten per route
- Optionele TOTP-tweestapsverificatie voor medewerkers
- Inloggen met SAML 2.0, OAuth 2 / OIDC of JWT

</td>
<td width="33%" valign="top">

**E-mail en webhooks**
- Inkomend via Mailgun, Postmark en Amazon SES
- Meerdere webhook-endpoints met eigen events
- Afleverlog en nieuwe pogingen

</td>
<td width="33%" valign="top">

**API en rapporten**
- REST API met bearer-tokens en rechten per token
- Rate limiting per token
- Rapportageweergaven

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Alle functies in detail</a></sub></p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP"></a>
  <a href="https://www.mysql.com"><img src="https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL"></a>
</p>

## Snel aan de slag

1. Download [escalated.zip](https://github.com/escalated-dev/escalated-wordpress/releases/latest/download/escalated.zip) uit de nieuwste release.
2. Pak het uit in je pluginmap, zodat de plugin in `wp-content/plugins/escalated` staat.
3. Activeer **Escalated** via het scherm Plugins in WordPress.
4. Ga in wp-admin naar **Escalated** en stel afdelingen, SLA-beleid, escalatieregels en instellingen in.

Vereist WordPress 6.0+ en PHP 8.1+. De [installatiehandleiding](../installation.md) bevat de details, en [aanpassen](../customization.md) noemt de shortcodes voor je klantgerichte pagina's.

## Meer informatie

- [Alle functies](../features.md) · [Installatie](../installation.md) · [Screenshots](../screenshots.md)
- [Shortcodes, ticketonderwerpen en vertalingen](../customization.md) · [REST API en tickets samenvoegen](../rest-api.md)
- [Toegangsbeheer](../access-control.md) · [Tickettoegang en routering](../ticket-access-and-routing.md) · [Workflows](../workflows.md)
- [Inkomende e-mail](../inbound-email.md) · [Webhooks](../webhooks.md) · [Gasttoegang](../guest-access.md)
- [De widget insluiten](../embedding-the-widget.md) · [De beheeromgeving insluiten](../embedding-admin.md)
- [Geplande taken en databaseverbinding](../operations.md) · [Nieuwsbrieven](../newsletters.md) · [Ontwikkeling](../development.md)

Escalated is ook beschikbaar voor [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Symfony](https://github.com/escalated-dev/escalated-symfony), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring) en [Filament](https://github.com/escalated-dev/escalated-filament). De UI voor de andere backends staat in [escalated](https://github.com/escalated-dev/escalated).

## Licentie

MIT. Copyright (c) 2026 Escalated.dev. Zie [LICENSE](../../LICENSE).
